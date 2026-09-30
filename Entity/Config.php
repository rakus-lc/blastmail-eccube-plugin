<?php

namespace Plugin\BlastmailSync\Entity;

use Doctrine\ORM\Mapping as ORM;

if (!class_exists('\Plugin\BlastmailSync\Entity\Config', false)) {
    /**
     * プラグイン設定（1行のみ、id=1）。
     *
     * 認証情報は DB に保存するが、環境変数 BLASTMAIL_SYNC_USERNAME / _PASSWORD / _API_KEY / _BASE_URL
     * があればそれを優先する（Service\BlastmailClient 参照）。
     *
     * @ORM\Table(name="plg_blastmail_sync_config")
     * @ORM\Entity(repositoryClass="Plugin\BlastmailSync\Repository\ConfigRepository")
     */
    class Config
    {
        public const TARGET_ACTIVE = 'active';        // 本会員すべて
        public const TARGET_MAILMAGA = 'mailmaga';    // メルマガ希望の本会員のみ（メルマガ管理プラグイン導入時）
        public const WITHDRAW_SENDSTOP = 'sendstop';  // 退会・削除時に blastmail 側を「配信停止」へ
        public const WITHDRAW_NONE = 'none';          // 何もしない
        public const MODE_FULL = 'full';              // 毎回全件を送る
        public const MODE_DIFF = 'diff';              // 前回同期からの変更分だけ送る

        /** blastmail 側を正とし、状態は一切送らない（新規登録時は blastmail 既定の「配信中」） */
        public const POLICY_SOURCE_INITIAL_ONLY = 'source_initial_only';
        /** EC-CUBE を正とし、毎回 EC-CUBE のオプト値で状態を送る（保護状態を除く） */
        public const POLICY_SOURCE_AUTHORITATIVE = 'source_authoritative';
        public const EVENT_TIMING_DEFERRED = 'deferred';   // 画面の応答を返した後に送る（推奨）
        public const EVENT_TIMING_IMMEDIATE = 'immediate'; // 画面の処理と同時に送る
        public const LOCK_DB = 'db';       // DB のアドバイザリロック（複数サーバー対応。推奨）
        public const LOCK_FILE = 'file';   // flock（単一サーバー向け）

        /** 変更イベントの新しい方を採用する（あと勝ち）。解除も上書きする */
        public const POLICY_INTENT_MERGE = 'intent_merge';
        /** あと勝ちと同じ判定だが、読者本人の「解除」だけは決して上書きしない（既定・推奨） */
        public const POLICY_INTENT_MERGE_SAFE = 'intent_merge_safe';

        /** 旧値 → 新値（v1.0.0 で使っていた名前からの移行） */
        private const LEGACY_POLICY = [
            'preserve' => self::POLICY_SOURCE_INITIAL_ONLY,
            'ec_authoritative' => self::POLICY_SOURCE_AUTHORITATIVE,
        ];

        /**
         * @ORM\Column(name="id", type="integer", options={"unsigned":true})
         * @ORM\Id
         * @ORM\GeneratedValue(strategy="IDENTITY")
         */
        private $id;

        /** @ORM\Column(name="api_base_url", type="string", length=255, options={"default":"https://api.bme.jp/rest/1.0"}) */
        private $apiBaseUrl = 'https://api.bme.jp/rest/1.0';

        /** @ORM\Column(name="username", type="string", length=255, nullable=true) */
        private $username;

        /** @ORM\Column(name="password", type="string", length=255, nullable=true) */
        private $password;

        /** @ORM\Column(name="api_key", type="string", length=255, nullable=true) */
        private $apiKey;

        /** @ORM\Column(name="sync_target", type="string", length=32, options={"default":"active"}) */
        private $syncTarget = self::TARGET_ACTIVE;

        /** @ORM\Column(name="withdraw_action", type="string", length=32, options={"default":"sendstop"}) */
        private $withdrawAction = self::WITHDRAW_SENDSTOP;

        /** @ORM\Column(name="csv_charset", type="string", length=32, options={"default":"UTF-8"}) */
        private $csvCharset = 'UTF-8';

        /** @ORM\Column(name="sync_mode", type="string", length=16, options={"default":"full"}) */
        private $syncMode = self::MODE_FULL;

        /**
         * 1 回のインポートで送る最大行数（これを超えると CSV を分割して逐次送信）
         *
         * @ORM\Column(name="batch_size", type="integer", options={"default":50000})
         */
        private $batchSize = 50000;

        /** @ORM\Column(name="status_policy", type="string", length=32, options={"default":"intent_merge"}) */
        private $statusPolicy = self::POLICY_INTENT_MERGE_SAFE;

        /**
         * 「解除」「エラー停止」の読者を上書きしない（既定 true）。
         * 解除の上書きは本人の意思に反し、エラー停止を戻すとバウンスが増えるため。
         *
         * @ORM\Column(name="protect_statuses", type="boolean", options={"default":true})
         */
        private $protectStatuses = true;   // 未使用（2026-09-24 に設定を廃止。列は互換のため残す）

        /**
         * EC-CUBE 項目キー → blastmail 項目表示名 のマッピング（JSON）。空文字は同期しない。
         *
         * @ORM\Column(name="mapping", type="text", nullable=true)
         */
        private $mapping;

        /**
         * 会員イベントの反映方法。deferred=画面の応答を返した後に送る（既定・推奨）／immediate=画面の処理と同時。
         *
         * @ORM\Column(name="event_sync_timing", type="string", length=16, options={"default":"deferred"})
         */
        private $eventSyncTiming = self::EVENT_TIMING_DEFERRED;

        /**
         * 同時実行ロックの方式。db=DB のアドバイザリロック（既定・複数サーバー対応）／file=flock。
         * SQLite など対応しない DB では自動でファイルに切り替わる。
         *
         * @ORM\Column(name="lock_mode", type="string", length=8, options={"default":"db"})
         */
        private $lockMode = self::LOCK_DB;

        /** @ORM\Column(name="event_sync_enabled", type="boolean", options={"default":true}) */
        private $eventSyncEnabled = true;

        /**
         * 双方向オプトアウト。同期時に blastmail 側の「解除」「配信停止」の読者を会員の受信可否（オプトアウト）に反映する。
         * 「配信中」は戻さない（同意は本人が EC-CUBE 側で表明したものだけを使う）。既定は有効。
         *
         * @ORM\Column(name="optout_import_enabled", type="boolean", options={"default":true})
         */
        private $optoutImportEnabled = true;

        /**
         * エラー停止連携。blastmail の「エラー停止」読者を会員の宛先状態（blastengine 連携プラグインが持つ列）に取り込み、
         * 宛先不明の会員は受信可否に関係なく「エラー停止」として blastmail に送る。既定は無効。
         *
         * @ORM\Column(name="bounce_sync_enabled", type="boolean", options={"default":false})
         */
        private $bounceSyncEnabled = false;

        /** @ORM\Column(name="last_sync_at", type="datetimetz", nullable=true) */
        private $lastSyncAt;

        public function getId()
        {
            return $this->id;
        }

        public function getApiBaseUrl(): string
        {
            return rtrim((string) $this->apiBaseUrl, '/');
        }

        public function setApiBaseUrl(?string $v): self
        {
            $this->apiBaseUrl = $v;

            return $this;
        }

        public function getUsername(): ?string
        {
            return $this->username;
        }

        public function setUsername(?string $v): self
        {
            $this->username = $v;

            return $this;
        }

        public function getPassword(): ?string
        {
            return $this->password;
        }

        public function setPassword(?string $v): self
        {
            $this->password = $v;

            return $this;
        }

        public function getApiKey(): ?string
        {
            return $this->apiKey;
        }

        public function setApiKey(?string $v): self
        {
            $this->apiKey = $v;

            return $this;
        }

        public function getSyncTarget(): string
        {
            return $this->syncTarget;
        }

        public function setSyncTarget(string $v): self
        {
            $this->syncTarget = $v;

            return $this;
        }

        public function getWithdrawAction(): string
        {
            return $this->withdrawAction;
        }

        public function setWithdrawAction(string $v): self
        {
            $this->withdrawAction = $v;

            return $this;
        }

        public function getCsvCharset(): string
        {
            return $this->csvCharset ?: 'UTF-8';
        }

        public function setCsvCharset(string $v): self
        {
            $this->csvCharset = $v;

            return $this;
        }

        public function getSyncMode(): string
        {
            return $this->syncMode ?: self::MODE_FULL;
        }

        public function setSyncMode(string $v): self
        {
            $this->syncMode = $v;

            return $this;
        }

        public function getBatchSize(): int
        {
            return max(100, (int) $this->batchSize ?: 50000);
        }

        public function setBatchSize(int $v): self
        {
            $this->batchSize = $v;

            return $this;
        }

        public function isDiffMode(): bool
        {
            return $this->getSyncMode() === self::MODE_DIFF;
        }

        public function getStatusPolicy(): string
        {
            $v = (string) $this->statusPolicy;

            return self::LEGACY_POLICY[$v] ?? ($v ?: self::POLICY_INTENT_MERGE_SAFE);
        }

        public function setStatusPolicy(string $v): self
        {
            $this->statusPolicy = self::LEGACY_POLICY[$v] ?? $v;

            return $this;
        }

        public function isProtectStatuses(): bool
        {
            return (bool) $this->protectStatuses;
        }

        public function setProtectStatuses(bool $v): self
        {
            $this->protectStatuses = $v;

            return $this;
        }

        /** @return array<string,string> */
        public function getMapping(): array
        {
            $m = json_decode((string) $this->mapping, true);

            return is_array($m) ? $m : [];
        }

        public function setMapping(array $m): self
        {
            $this->mapping = json_encode(array_filter($m, fn ($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE);

            return $this;
        }

        public function isOptoutImportEnabled(): bool
        {
            return (bool) $this->optoutImportEnabled;
        }

        public function setOptoutImportEnabled(bool $v): self
        {
            $this->optoutImportEnabled = $v;

            return $this;
        }

        public function isBounceSyncEnabled(): bool
        {
            return (bool) $this->bounceSyncEnabled;
        }

        public function setBounceSyncEnabled(bool $v): self
        {
            $this->bounceSyncEnabled = $v;

            return $this;
        }

        public function isEventSyncEnabled(): bool
        {
            return (bool) $this->eventSyncEnabled;
        }

        public function setEventSyncEnabled(bool $v): self
        {
            $this->eventSyncEnabled = $v;

            return $this;
        }

        public function getLastSyncAt(): ?\DateTimeInterface
        {
            return $this->lastSyncAt;
        }

        public function setLastSyncAt(?\DateTimeInterface $v): self
        {
            $this->lastSyncAt = $v;

            return $this;
        }

        public function getEventSyncTiming(): string
        {
            $v = (string) $this->eventSyncTiming;

            return $v === self::EVENT_TIMING_IMMEDIATE ? $v : self::EVENT_TIMING_DEFERRED;
        }

        public function setEventSyncTiming(string $v): self
        {
            $this->eventSyncTiming = $v;

            return $this;
        }

        public function getLockMode(): string
        {
            $v = (string) $this->lockMode;

            return $v === self::LOCK_FILE ? $v : self::LOCK_DB;
        }

        public function setLockMode(string $v): self
        {
            $this->lockMode = $v;

            return $this;
        }

    }
}
