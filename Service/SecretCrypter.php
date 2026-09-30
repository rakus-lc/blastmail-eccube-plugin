<?php

namespace Plugin\BlastmailSync\Service;

use Eccube\Common\EccubeConfig;

/**
 * blastmail の認証情報（パスワード・API キー）を DB に保存するときの暗号化。
 *
 * 鍵はサーバー側の秘密値 ECCUBE_AUTH_MAGIC（環境変数／.env。DB には無い）から HKDF で派生させる。
 * これにより DB ダンプだけが流出しても復号できない（サーバーの環境変数まで漏れた場合は防げない）。
 *
 * 形式: "enc1:" + base64(nonce(24) + ciphertext)。libsodium の secretbox（XSalsa20-Poly1305）。
 * 接頭辞の無い値は旧来の平文として扱い、次に設定を保存したときに暗号化へ移行する。
 * ECCUBE_AUTH_MAGIC を変更すると復号できなくなるので、その場合は設定画面で再入力する。
 */
class SecretCrypter
{
    private const PREFIX = 'enc1:';
    private const CONTEXT = 'BlastmailSync/credentials/v1';

    public function __construct(private EccubeConfig $eccubeConfig)
    {
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key());

        return self::PREFIX.base64_encode($nonce.$cipher);
    }

    /**
     * @throws \RuntimeException 復号に失敗した場合（鍵の変更・データ破損）
     */
    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return $stored; // 旧来の平文
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('保存された認証情報を読めません（データ破損）。設定画面で再入力してください。');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if ($plain === false) {
            throw new \RuntimeException('保存された認証情報を復号できません。ECCUBE_AUTH_MAGIC が変更された可能性があります。設定画面で再入力してください。');
        }

        return $plain;
    }

    public function isEncrypted(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::PREFIX);
    }

    /** サーバー秘密値が既定のままなら暗号化の意味が薄い。画面で警告するために公開 */
    public function isServerSecretWeak(): bool
    {
        $magic = (string) $this->eccubeConfig->get('eccube_auth_magic');

        return $magic === '' || $magic === '<change.me>' || strlen($magic) < 16;
    }

    private function key(): string
    {
        $magic = (string) $this->eccubeConfig->get('eccube_auth_magic');
        if ($magic === '') {
            throw new \RuntimeException('ECCUBE_AUTH_MAGIC が設定されていないため認証情報を暗号化できません。');
        }
        // HKDF-SHA256 で 32 バイト鍵を派生（用途文字列で他用途と分離）
        return hash_hkdf('sha256', $magic, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::CONTEXT);
    }
}
