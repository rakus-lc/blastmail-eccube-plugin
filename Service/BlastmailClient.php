<?php

namespace Plugin\BlastmailSync\Service;

use GuzzleHttp\Client as HttpClient;
use Plugin\BlastmailSync\Entity\Config;
use Plugin\BlastmailSync\Repository\ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * blastmail 外部公開API（REST, api.bme.jp/rest/1.0）クライアント。
 *
 * 仕様の根拠: 公開ドキュメント（blastmail.jp/api/）と実測（2026-08-24。2026-09-24 に実アカウントで再確認）。
 * 要点:
 *  - login: POST authenticate/login (username, password, api_key) → {"accessToken": "..."}
 *  - 以降は access_token をクエリ/ボディ/multipart 本文で渡す。401 なら再ログイン
 *  - 一括 upsert: POST contact/import/all（multipart。CSV ヘッダは項目「表示名」、BOM なし・LF）
 *  - 個別更新: POST contact/detail/update (contactID, status=配信停止 等)
 *  - 配信履歴: GET message/history/search
 */
class BlastmailClient
{
    /**
     * レスポンス形式の指定に使うパラメータ名。
     *
     * 公式リファレンスの表記は `f`。実環境（api.bme.jp、2026-09-04 実測）では GET に `format=json` を
     * 付けると `illegal format: json,json` で HTTP 500 になることがあるため、常に `f=json` を使う。
     */
    private const FORMAT_PARAM = 'f';

    private ?string $token = null;
    private ?HttpClient $http = null;

    public function __construct(
        private ConfigRepository $configRepository,
        private SecretCrypter $crypter,
        private LoggerInterface $logger,
    ) {
    }

    // ---- 認証情報（環境変数 > DB。DB 保存値は SecretCrypter で暗号化されている） ----

    public function getCredentials(): array
    {
        $Config = $this->configRepository->get();

        return [
            'base_url' => rtrim(self::env('BLASTMAIL_SYNC_BASE_URL') ?: $Config->getApiBaseUrl(), '/'),
            'username' => self::env('BLASTMAIL_SYNC_USERNAME') ?: (string) $Config->getUsername(),
            'password' => self::env('BLASTMAIL_SYNC_PASSWORD') ?: (string) $this->crypter->decrypt($Config->getPassword()),
            'api_key' => self::env('BLASTMAIL_SYNC_API_KEY') ?: (string) $this->crypter->decrypt($Config->getApiKey()),
        ];
    }

    /**
     * 環境変数を読む。
     *
     * Symfony の Dotenv は既定で putenv() を呼ばない（usePutenv=false）ため、`.env` に書いた値は
     * getenv() では取れず $_ENV / $_SERVER にしか入らない。実プロセスの環境変数（docker の environment、
     * SetEnv、systemd 等）は getenv() でも取れる。どちらでも拾えるよう 3 つとも見る。
     */
    public static function env(string $name): string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return '';
    }

    public function isConfigured(): bool
    {
        $c = $this->getCredentials();

        return $c['username'] !== '' && $c['password'] !== '' && $c['api_key'] !== '';
    }

    private function http(): HttpClient
    {
        if ($this->http === null) {
            $this->http = new HttpClient([
                'base_uri' => $this->getCredentials()['base_url'].'/',
                'timeout' => 120,
                'http_errors' => false,
            ]);
        }

        return $this->http;
    }

    public function login(): string
    {
        $c = $this->getCredentials();
        if (!$this->isConfigured()) {
            throw new BlastmailApiException('blastmail の API 認証情報が設定されていません。', 0);
        }
        $res = $this->http()->post('authenticate/login', [
            'form_params' => ['username' => $c['username'], 'password' => $c['password'], 'api_key' => $c['api_key'], self::FORMAT_PARAM => 'json'],
        ]);
        $body = (string) $res->getBody();
        if ($res->getStatusCode() !== 200) {
            throw BlastmailApiException::fromResponse($res->getStatusCode(), $body);
        }
        $json = self::decode($body);
        $token = $json['accessToken'] ?? $json['access_token'] ?? ($json['authenticate']['accessToken'] ?? null);
        if (!$token) {
            throw new BlastmailApiException('login レスポンスに accessToken がありません: '.mb_substr($body, 0, 200), $res->getStatusCode());
        }
        $this->token = $token;

        return $token;
    }

    /**
     * 共通呼び出し。401 のときは一度だけ再ログインして再試行する。
     *
     * @param array $params  フォーム/クエリパラメータ
     * @param array|null $file ['name' => 'import.csv', 'contents' => '...'] を渡すと multipart
     *
     * @return array{status:int, body:string}
     */
    private function call(string $method, string $path, array $params = [], ?array $file = null): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($this->token === null) {
                $this->login();
            }
            $params['access_token'] = $this->token;
            $params[self::FORMAT_PARAM] = 'json';

            if ($file !== null) {
                $multipart = [];
                foreach ($params as $k => $v) {
                    $multipart[] = ['name' => $k, 'contents' => (string) $v];
                }
                $multipart[] = ['name' => 'file', 'contents' => $file['contents'], 'filename' => $file['name']];
                $res = $this->http()->request($method, $path, ['multipart' => $multipart]);
            } elseif ($method === 'GET') {
                $res = $this->http()->get($path, ['query' => $params]);
            } else {
                $res = $this->http()->request($method, $path, ['form_params' => $params]);
            }
            $status = $res->getStatusCode();
            $body = (string) $res->getBody();
            if ($status === 401 && $attempt === 0) {
                $this->logger->info('[BlastmailSync] token expired, re-login');
                $this->token = null;
                continue;
            }
            if ($status !== 200) {
                throw BlastmailApiException::fromResponse($status, $body);
            }

            return ['status' => $status, 'body' => $body];
        }
        throw new BlastmailApiException('認証に失敗しました。', 401);
    }

    // ---- 読者項目 ----

    /**
     * 項目定義一覧。[{id: 'c15', name: 'E-Mail', type: ..., uses: bool}, ...]
     */
    public function defineList(): array
    {
        // 応答は {"definition":[{defineID,type,name,content,uses,must,display,position}]}（実測）。入れ子の揺れに耐えるため再帰で探す
        $r = $this->call('GET', 'define/list/search');
        $json = self::decode($r['body']);
        $out = [];
        $walk = function ($v) use (&$walk, &$out) {
            if (!is_array($v)) {
                return;
            }
            if (isset($v['defineID'], $v['name'])) {
                $uses = $v['uses'] ?? true;
                $out[] = [
                    'id' => (string) $v['defineID'],
                    'name' => (string) $v['name'],
                    'type' => (string) ($v['type'] ?? ''),
                    'uses' => is_bool($uses) ? $uses : in_array((string) $uses, ['true', '1'], true),
                ];

                return;
            }
            foreach ($v as $vv) {
                $walk($vv);
            }
        };
        $walk($json);

        return $out;
    }

    /**
     * 状態を指定して読者を CSV エクスポートし、メールアドレスの集合を返す（小文字化）。
     *
     * 「解除」「エラー停止」など、上書きしてはいけない読者を洗い出すために使う。
     * エクスポートの文字コードはアカウント設定依存（既定 MS932）なので判定して UTF-8 に寄せる。
     *
     * 「解除」「削除」の読者は contact/list/export では取れない（API 側が解除・削除を
     * 「無効な読者」として別扱いし、指定すると HTTP 400 Failure_Data is incorrect になる）。
     * この 2 つは contact/trash/export から取る。2026-09-24 実アカウントで確認。
     *
     * @return array<string, true> email(小文字) => true
     */
    public function exportEmailsByStatus(string $status, string $emailHeader): array
    {
        $path = in_array($status, ['解除', '削除'], true) ? 'contact/trash/export' : 'contact/list/export';
        $r = $this->call('GET', $path, ['status' => $status]);
        $csv = $r['body'];
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'SJIS-win');
        }
        $lines = preg_split('/\r\n|\r|\n/', $csv);
        $header = str_getcsv((string) array_shift($lines));
        $idx = array_search($emailHeader, $header, true);
        if ($idx === false) {
            // 表示名が一致しない場合はメールアドレスらしい列を探す
            foreach ($header as $i => $h) {
                if (stripos($h, 'mail') !== false || str_contains($h, 'メール')) {
                    $idx = $i;
                    break;
                }
            }
        }
        $out = [];
        if ($idx === false) {
            return $out;
        }
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if (isset($cols[$idx]) && $cols[$idx] !== '') {
                $out[strtolower(trim($cols[$idx]))] = true;
            }
        }

        return $out;
    }

    /** メールアドレス項目（固定で c15）の表示名。既定 'E-Mail'。 */
    public static function emailDefineName(array $defines): string
    {
        foreach ($defines as $d) {
            if ($d['id'] === 'c15') {
                return $d['name'];
            }
        }

        return 'E-Mail';
    }

    // ---- 読者 ----

    /**
     * 一括 upsert（新規は登録、既存は CSV にある列のみ更新）。
     *
     * @return array{success:int, failure:int, details:array<int, array{lineNumber:int, content:string, message:string}>}
     */
    public function importAll(string $csv): array
    {
        // 同一アカウントで別のインポートが処理中だと Failure_Request during processing（HTTP 400）になる。
        // インポートはアカウント単位で排他される（実測）ので、少し待って再試行する。
        $r = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $r = $this->call('POST', 'contact/import/all', [], ['name' => 'import.csv', 'contents' => $csv]);
                break;
            } catch (BlastmailApiException $e) {
                if ($attempt < 4 && str_contains($e->getMessage(), 'during processing')) {
                    $this->logger->info(sprintf('[BlastmailSync] import busy, retry %d/3 after 15s', $attempt));
                    sleep(15);
                    continue;
                }
                throw $e;
            }
        }
        $json = self::decode($r['body']);
        $root = $json;
        foreach (['contact', 'import', 'result'] as $k) {
            if (isset($root[$k]) && is_array($root[$k])) {
                $root = $root[$k];
            }
        }
        $details = [];
        $list = $root['details']['detail'] ?? $root['details'] ?? [];
        if (isset($list['lineNumber'])) {
            $list = [$list];
        }
        foreach ((array) $list as $d) {
            if (is_array($d)) {
                $details[] = [
                    'lineNumber' => (int) ($d['lineNumber'] ?? 0),
                    'content' => (string) ($d['content'] ?? ''),
                    'message' => (string) ($d['message'] ?? ''),
                ];
            }
        }

        return [
            'success' => (int) ($root['success'] ?? 0),
            'failure' => (int) ($root['failure'] ?? 0),
            'details' => $details,
        ];
    }

    /** メールアドレスで読者を検索し contactID を返す。未登録なら null。 */
    public function findContactIdByEmail(string $email): ?string
    {
        try {
            $r = $this->call('GET', 'contact/detail/search', ['email' => $email]);
        } catch (BlastmailApiException $e) {
            if (str_contains($e->getMessage(), 'No data')) {
                return null;
            }
            throw $e;
        }
        $json = self::decode($r['body']);
        $found = null;
        $walk = function ($v) use (&$walk, &$found) {
            if ($found !== null || !is_array($v)) {
                return;
            }
            foreach (['contactID', 'contactId', 'contact_id'] as $k) {
                if (isset($v[$k])) {
                    $found = (string) $v[$k];

                    return;
                }
            }
            foreach ($v as $vv) {
                $walk($vv);
            }
        };
        $walk($json);

        return $found;
    }

    /** メールアドレスで読者の現在の状態（配信中／配信停止／解除／エラー停止）を返す。未登録なら null。 */
    public function findContactStatusByEmail(string $email): ?string
    {
        try {
            $r = $this->call('GET', 'contact/detail/search', ['email' => $email]);
        } catch (BlastmailApiException $e) {
            if (str_contains($e->getMessage(), 'No data')) {
                return null;
            }
            throw $e;
        }
        $json = self::decode($r['body']);
        $found = null;
        $walk = function ($v) use (&$walk, &$found) {
            if ($found !== null || !is_array($v)) {
                return;
            }
            if (isset($v['status']) && is_string($v['status'])) {
                $found = $v['status'];

                return;
            }
            foreach ($v as $vv) {
                $walk($vv);
            }
        };
        $walk($json);

        return $found;
    }

    /**
     * メールアドレスで読者を検索し、レコード全体（defineID => 値、status ほか）を返す。未登録なら null。
     * 疎通確認・検証用（blastmail:probe reader）。
     *
     * @return array<string, mixed>|null
     */
    public function findContactByEmail(string $email): ?array
    {
        try {
            $r = $this->call('GET', 'contact/detail/search', ['email' => $email]);
        } catch (BlastmailApiException $e) {
            if (str_contains($e->getMessage(), 'No data')) {
                return null;
            }
            throw $e;
        }
        $json = self::decode($r['body']);
        $found = null;
        $walk = function ($v) use (&$walk, &$found) {
            if ($found !== null || !is_array($v)) {
                return;
            }
            // 読者レコードは c15（E-Mail）または status を持つ連想配列
            if (isset($v['c15']) || isset($v['status'])) {
                $found = $v;

                return;
            }
            foreach ($v as $vv) {
                $walk($vv);
            }
        };
        $walk($json);

        return $found;
    }

    /** 読者の個別更新。$fields のキーは defineID（c15 等）または status。 */
    public function updateContact(string $contactId, array $fields): void
    {
        $this->call('POST', 'contact/detail/update', ['contactID' => $contactId] + $fields);
    }

    /** 読者の状態を変更する（値は表示文字列: 配信中/配信停止/解除）。未登録なら false。 */
    public function setContactStatusByEmail(string $email, string $status): bool
    {
        $id = $this->findContactIdByEmail($email);
        if ($id === null) {
            return false;
        }
        $this->updateContact($id, ['status' => $status]);

        return true;
    }

    // ---- 配信履歴 ----

    /**
     * 直近の配信履歴。[{messageID, date, subject, group, total, success, failure, status}, ...]
     */
    public function historySearch(int $limit = 10): array
    {
        $r = $this->call('GET', 'message/history/search', ['limit' => $limit, 'offset' => 0]);
        $json = self::decode($r['body']);
        $out = [];
        $walk = function ($v) use (&$walk, &$out) {
            if (!is_array($v)) {
                return;
            }
            if (isset($v['messageID']) && (isset($v['subject']) || isset($v['date']))) {
                $out[] = $v;

                return;
            }
            foreach ($v as $vv) {
                $walk($vv);
            }
        };
        $walk($json);

        return $out;
    }

    /**
     * レスポンス本文を配列にする。JSON を既定とし、XML が返ってきた場合も読めるようにする
     * （形式指定が効かない環境では XML が返る）。
     */
    private static function decode(string $body): array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        if (str_starts_with(ltrim($body), '<')) {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_use_internal_errors($prev);
            if ($xml !== false) {
                return json_decode(json_encode($xml), true) ?: [];
            }
        }

        return [];
    }

    /** 接続テスト: ログインして項目一覧を取る。 */
    public function testConnection(): array
    {
        $this->token = null;
        $this->login();

        return $this->defineList();
    }
}
