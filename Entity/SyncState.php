<?php

namespace Plugin\BlastmailSync\Entity;

use Doctrine\ORM\Mapping as ORM;

if (!class_exists('\Plugin\BlastmailSync\Entity\SyncState', false)) {
    /**
     * 差分同期用の状態。会員ごとに「前回送った行のハッシュ」と「前回送ったメールアドレス」を持つ。
     *
     * @ORM\Table(name="plg_blastmail_sync_state")
     * @ORM\Entity(repositoryClass="Plugin\BlastmailSync\Repository\SyncStateRepository")
     */
    class SyncState
    {
        /**
         * EC-CUBE 会員 ID
         *
         * @ORM\Column(name="customer_id", type="integer", options={"unsigned":true})
         * @ORM\Id
         */
        private $customerId;

        /** @ORM\Column(name="email", type="string", length=255) */
        private $email;

        /** @ORM\Column(name="row_hash", type="string", length=64) */
        private $rowHash;

        /**
         * 前回連携時の EC-CUBE 側オプト値（intent_merge の決定表で使う prev_src）
         *
         * @ORM\Column(name="opt_in", type="boolean", options={"default":true})
         */
        private $optIn = true;

        /**
         * 前回同期時の blastmail 側の配信状態（配信中／配信停止／解除／エラー停止）。意図マージの判定に使う
         *
         * @ORM\Column(name="bm_status", type="string", length=16, nullable=true)
         */
        private $bmStatus;

        /** @ORM\Column(name="synced_at", type="datetimetz") */
        private $syncedAt;

        /**
         * 退会・同期対象外になって「配信停止」を送った状態。復帰したら受信可否どおりに戻す目印。
         * 自分が送った配信停止を、次の同期で「blastmail 側の意思」として読み戻さないために持つ。
         *
         * @ORM\Column(name="withdrawn", type="boolean", options={"default":false})
         */
        private $withdrawn = false;

        public function __construct(int $customerId)
        {
            $this->customerId = $customerId;
        }

        public function getCustomerId(): int
        {
            return $this->customerId;
        }

        public function getEmail(): string
        {
            return $this->email;
        }

        public function setEmail(string $v): self
        {
            $this->email = $v;

            return $this;
        }

        public function getRowHash(): string
        {
            return $this->rowHash;
        }

        public function setRowHash(string $v): self
        {
            $this->rowHash = $v;

            return $this;
        }

        public function isOptIn(): bool
        {
            return (bool) $this->optIn;
        }

        public function setOptIn(bool $v): self
        {
            $this->optIn = $v;

            return $this;
        }

        public function getBmStatus(): ?string
        {
            return $this->bmStatus;
        }

        public function setBmStatus(?string $v): self
        {
            $this->bmStatus = $v;

            return $this;
        }

        public function getSyncedAt(): \DateTimeInterface
        {
            return $this->syncedAt;
        }

        public function setSyncedAt(\DateTimeInterface $v): self
        {
            $this->syncedAt = $v;

            return $this;
        }

        public function isWithdrawn(): bool
        {
            return (bool) $this->withdrawn;
        }

        public function setWithdrawn(bool $v): self
        {
            $this->withdrawn = $v;

            return $this;
        }

    }
}
