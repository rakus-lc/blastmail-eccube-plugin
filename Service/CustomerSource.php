<?php

namespace Plugin\BlastmailSync\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Customer;
use Eccube\Entity\Master\CustomerStatus;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Plugin\BlastmailSync\Entity\Config;

/**
 * EC-CUBE 会員を blastmail 読者行へ変換する。
 *
 * 「項目キー」は設定画面のマッピングで blastmail 側の項目表示名に対応づける。
 * 購買系の値は、キャンセル・決済処理中・返品・入金待ちを除いた受注から集計する。
 */
class CustomerSource
{
    /** 購買集計から除外する受注ステータス */
    private const EXCLUDED_ORDER_STATUS = [OrderStatus::CANCEL, OrderStatus::PENDING, OrderStatus::PROCESSING, OrderStatus::RETURNED];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * 同期できる項目の一覧。key => [label, description, blastmail 側の既定の項目名]
     *
     * 3 番目は「項目名を自動で割り当てる」が照合に使う名前（DefineMatcher）。
     */
    public static function fieldCatalog(): array
    {
        return [
            'customer_id' => ['EC-CUBE 会員ID', 'blastmail 側で会員を特定するキー', 'EC会員ID'],
            'name' => ['氏名（姓 名）', '例: ダミー 会員001', '氏名'],
            'name01' => ['姓', '', '姓'],
            'name02' => ['名', '', '名'],
            'kana' => ['フリガナ（セイ メイ）', '', 'フリガナ'],
            'company_name' => ['会社名', '', '会社名'],
            'pref' => ['都道府県', '', '都道府県'],
            'create_date' => ['会員登録日', '入力タイプは「年月日」', '会員登録日'],
            'point' => ['保有ポイント', '', '保有ポイント'],
            'order_count' => ['購入回数', 'キャンセル等を除く', '購入回数'],
            'total_amount' => ['累計購入額', '税込・送料込の支払合計', '累計購入額'],
            'last_order_date' => ['最終購入日', '入力タイプは「年月日」。未購入は空', '最終購入日'],
            'last_order_product' => ['最終購入商品', '直近受注の先頭商品名', '最終購入商品'],
            'last_order_category' => ['最終購入カテゴリ', '直近受注の先頭商品のカテゴリ名', '最終購入カテゴリ'],
        ];
    }

    /**
     * 同期対象の会員を返す。
     *
     * @return Customer[]
     */
    public function findTargets(Config $Config): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')->from(Customer::class, 'c')
            ->where('c.Status = :status')->setParameter('status', CustomerStatus::ACTIVE)
            ->orderBy('c.id', 'ASC');
        $customers = $qb->getQuery()->getResult();

        if ($Config->getSyncTarget() === Config::TARGET_MAILMAGA) {
            $customers = array_values(array_filter($customers, fn (Customer $c) => $this->isOptIn($c)));
        }

        return $customers;
    }

    /**
     * 同期対象の会員を ID 順に 1 チャンク返す（ストリーミング用）。
     * 全件をメモリに載せないため、呼び出し側は処理後に EntityManager::clear() すること。
     *
     * @return Customer[] 空なら終端
     */
    public function findTargetChunk(Config $Config, int $afterId, int $limit = 1000): array
    {
        $customers = $this->em->createQueryBuilder()
            ->select('c')->from(Customer::class, 'c')
            ->where('c.Status = :status')->setParameter('status', CustomerStatus::ACTIVE)
            ->andWhere('c.id > :after')->setParameter('after', $afterId)
            ->orderBy('c.id', 'ASC')->setMaxResults($limit)
            ->getQuery()->getResult();

        return $customers;
    }

    /** 同期対象の概算件数（本会員数。メルマガ希望のみの設定でもここでは絞らない） */
    public function countActive(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')->from(Customer::class, 'c')
            ->where('c.Status = :status')->setParameter('status', CustomerStatus::ACTIVE)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * EC-CUBE 側のオプト値（true = 配信してよい）。
     *
     * 1. 公式のメルマガ管理プラグイン等が `mailmaga_flg` を持っていればそれを使う
     * 2. 無ければ本プラグインが会員に追加する受信可否（blastmail_opt_in）を使う
     * 3. どちらも無ければ本会員をオプトインとみなす（退会・削除は別途「配信停止」を送る）
     */
    public function isOptIn(Customer $Customer): bool
    {
        if (method_exists($Customer, 'getMailmagaFlg')) {
            return (bool) $Customer->getMailmagaFlg();
        }
        if (method_exists($Customer, 'getBlastmailOptIn')) {
            return (bool) $Customer->getBlastmailOptIn();
        }

        return true;
    }

    /**
     * 受信可否を保持している dtb_customer の列名（無ければ null）。
     * 双方向オプトアウトの取り込みで DBAL から直接更新するために使う。
     */
    public function optInColumn(): ?string
    {
        if ($this->hasExternalOptInField()) {
            return 'mailmaga_flg';
        }
        if (method_exists(Customer::class, 'getBlastmailOptIn')) {
            return 'blastmail_opt_in';
        }

        return null;
    }

    /** 他プラグイン由来の受信可否項目があるか（あれば本プラグインは項目を追加しない） */
    public function hasExternalOptInField(): bool
    {
        return method_exists(Customer::class, 'getMailmagaFlg');
    }

    /** 会員ごとの受信可否を扱えるか */
    public function hasOptInField(): bool
    {
        return $this->hasExternalOptInField() || method_exists(Customer::class, 'getBlastmailOptIn');
    }

    /** 受信可否の由来（画面表示用） */
    public function optInSource(): string
    {
        if ($this->hasExternalOptInField()) {
            return 'メルマガ管理プラグイン等の項目（mailmaga_flg）';
        }
        if (method_exists(Customer::class, 'getBlastmailOptIn')) {
            return '本プラグインが会員に追加した「メールマガジンを受信する」';
        }

        return '';
    }

    public function isTarget(Customer $Customer, Config $Config): bool
    {
        if ($Customer->getStatus() === null || $Customer->getStatus()->getId() !== CustomerStatus::ACTIVE) {
            return false;
        }
        if ($Config->getSyncTarget() === Config::TARGET_MAILMAGA) {
            return $this->isOptIn($Customer);
        }

        return true;
    }

    /** 受注集計が必要になる項目キー */
    private const ORDER_AGG_KEYS = ['order_count', 'total_amount', 'last_order_date', 'last_order_product', 'last_order_category'];
    /** 直近受注の実体（商品・カテゴリ）が必要になる項目キー */
    private const LAST_ORDER_KEYS = ['last_order_product', 'last_order_category'];

    /**
     * 会員 1 件を 項目キー => 値 の配列にする（email を含む、全項目）。
     */
    public function toRow(Customer $Customer): array
    {
        return $this->toRows([$Customer])[$Customer->getId()];
    }

    /**
     * 複数会員をまとめて行にする。会員 ID => 行。
     *
     * $keys に含まれる項目だけ計算する（null なら全項目）。受注集計は会員ごとに投げず、
     * 対象会員全体を 1 クエリで GROUP BY し、直近受注の実体も必要なときだけまとめて取る。
     *
     * @param Customer[] $customers
     * @param string[]|null $keys
     */
    public function toRows(array $customers, ?array $keys = null): array
    {
        $keys = $keys ?? array_keys(self::fieldCatalog());
        $needAgg = array_intersect($keys, self::ORDER_AGG_KEYS) !== [];
        $needLast = array_intersect($keys, self::LAST_ORDER_KEYS) !== [];

        $agg = $needAgg ? $this->aggregateOrdersBulk($customers, $needLast) : [];

        $rows = [];
        foreach ($customers as $Customer) {
            $a = $agg[$Customer->getId()] ?? ['count' => 0, 'total' => 0.0, 'last_date' => null, 'last' => null];
            $row = [
                'email' => (string) $Customer->getEmail(),
                'customer_id' => (string) $Customer->getId(),
                'name' => trim($Customer->getName01().' '.$Customer->getName02()),
                'name01' => (string) $Customer->getName01(),
                'name02' => (string) $Customer->getName02(),
                'kana' => trim((string) $Customer->getKana01().' '.(string) $Customer->getKana02()),
                'company_name' => (string) $Customer->getCompanyName(),
                'pref' => $Customer->getPref() ? (string) $Customer->getPref()->getName() : '',
                'create_date' => $Customer->getCreateDate() ? $Customer->getCreateDate()->format('Y/m/d') : '',
                'point' => (string) (int) $Customer->getPoint(),
            ];
            if ($needAgg) {
                $row['order_count'] = (string) $a['count'];
                $row['total_amount'] = (string) (int) $a['total'];
                $row['last_order_date'] = $a['last_date'] ? $a['last_date']->format('Y/m/d') : '';
            }
            if ($needLast) {
                $row['last_order_product'] = $a['last'] ? $this->firstProductName($a['last']) : '';
                $row['last_order_category'] = $a['last'] ? $this->firstCategoryName($a['last']) : '';
            }
            $rows[$Customer->getId()] = $row;
        }

        return $rows;
    }

    /**
     * 会員集合の受注集計を一括で取る。会員 ID => {count, total, last_date, last}
     *
     * @param Customer[] $customers
     */
    private function aggregateOrdersBulk(array $customers, bool $withLastOrder): array
    {
        $out = [];
        // IN 句が長くなりすぎないよう 1000 件ずつ
        foreach (array_chunk($customers, 1000) as $chunk) {
            $ids = array_map(fn (Customer $c) => $c->getId(), $chunk);
            $result = $this->em->createQueryBuilder()
                ->select('IDENTITY(o.Customer) AS cid', 'COUNT(o.id) AS cnt', 'COALESCE(SUM(o.payment_total), 0) AS total', 'MAX(o.order_date) AS last_date')
                ->from(Order::class, 'o')
                ->where('o.Customer IN (:ids)')->setParameter('ids', $ids)
                ->andWhere('o.OrderStatus NOT IN (:ex)')->setParameter('ex', self::EXCLUDED_ORDER_STATUS)
                ->groupBy('o.Customer')
                ->getQuery()->getArrayResult();
            $rawLastDates = []; // DB がそのまま返した文字列（IN 句に戻すため同じ表現を保つ）
            $tz = new \DateTimeZone(date_default_timezone_get());
            foreach ($result as $r) {
                $cid = (int) $r['cid'];
                $lastDate = $r['last_date'] ? (new \DateTime($r['last_date']))->setTimezone($tz) : null;
                $out[$cid] = ['count' => (int) $r['cnt'], 'total' => (float) $r['total'], 'last_date' => $lastDate, 'last' => null];
                if ($r['last_date']) {
                    $rawLastDates[$cid] = $r['last_date'];
                }
            }
            if ($withLastOrder && $rawLastDates !== []) {
                // 会員ごとの最終受注日時に一致する受注を候補としてまとめて取り、
                // PHP 側で会員ごとにタイムスタンプ最大のものを選ぶ（DB と PHP のタイムゾーン差に依存しない）
                // 商品名・カテゴリ名まで一度に取る（受注ごとの遅延ロードを避ける）
                $orders = $this->em->createQueryBuilder()
                    ->select('o', 'oi', 'p', 'pc', 'cat')->from(Order::class, 'o')
                    ->leftJoin('o.OrderItems', 'oi')
                    ->leftJoin('oi.Product', 'p')
                    ->leftJoin('p.ProductCategories', 'pc')
                    ->leftJoin('pc.Category', 'cat')
                    ->where('o.Customer IN (:ids)')->setParameter('ids', array_keys($rawLastDates))
                    ->andWhere('o.order_date IN (:dates)')->setParameter('dates', array_values(array_unique($rawLastDates)))
                    ->andWhere('o.OrderStatus NOT IN (:ex)')->setParameter('ex', self::EXCLUDED_ORDER_STATUS)
                    ->orderBy('o.id', 'DESC')
                    ->getQuery()->getResult();
                foreach ($orders as $Order) {
                    $cid = $Order->getCustomer()->getId();
                    if (!isset($out[$cid])) {
                        continue;
                    }
                    $cur = $out[$cid]['last'];
                    if ($cur === null || $Order->getOrderDate()->getTimestamp() > $cur->getOrderDate()->getTimestamp()) {
                        $out[$cid]['last'] = $Order;
                    }
                }
                foreach ($out as $cid => &$a) {
                    if ($a['last'] !== null) {
                        $a['last_date'] = $a['last']->getOrderDate(); // 表示は受注エンティティの日時（アプリのタイムゾーン）に揃える
                    }
                }
                unset($a);
            }
        }

        return $out;
    }

    private function firstProductName(Order $Order): string
    {
        foreach ($Order->getProductOrderItems() as $Item) {
            return (string) $Item->getProductName();
        }

        return '';
    }

    private function firstCategoryName(Order $Order): string
    {
        foreach ($Order->getProductOrderItems() as $Item) {
            $Product = $Item->getProduct();
            if ($Product) {
                foreach ($Product->getProductCategories() as $pc) {
                    return (string) $pc->getCategory()->getName();
                }
            }

            return '';
        }

        return '';
    }
}
