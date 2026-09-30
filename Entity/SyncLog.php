<?php

namespace Plugin\BlastmailSync\Entity;

use Doctrine\ORM\Mapping as ORM;

if (!class_exists('\Plugin\BlastmailSync\Entity\SyncLog', false)) {
    /**
     * 同期実行ログ。
     *
     * @ORM\Table(name="plg_blastmail_sync_log")
     * @ORM\Entity(repositoryClass="Plugin\BlastmailSync\Repository\SyncLogRepository")
     */
    class SyncLog
    {
        /**
         * @ORM\Column(name="id", type="integer", options={"unsigned":true})
         * @ORM\Id
         * @ORM\GeneratedValue(strategy="IDENTITY")
         */
        private $id;

        /** @ORM\Column(name="create_date", type="datetimetz") */
        private $createDate;

        /**
         * 実行契機: manual / command / event:<name>
         *
         * @ORM\Column(name="trigger_name", type="string", length=64)
         */
        private $trigger;

        /** @ORM\Column(name="total", type="integer", options={"default":0}) */
        private $total = 0;

        /** @ORM\Column(name="success", type="integer", options={"default":0}) */
        private $success = 0;

        /** @ORM\Column(name="failure", type="integer", options={"default":0}) */
        private $failure = 0;

        /** @ORM\Column(name="is_error", type="boolean", options={"default":false}) */
        private $error = false;

        /** @ORM\Column(name="message", type="text", nullable=true) */
        private $message;

        /**
         * running / done / error
         *
         * @ORM\Column(name="status", type="string", length=16, options={"default":"done"})
         */
        private $status = self::STATUS_DONE;

        /** 処理済み会員数（進捗表示用） @ORM\Column(name="processed", type="integer", options={"default":0}) */
        private $processed = 0;

        /** @ORM\Column(name="update_date", type="datetimetz", nullable=true) */
        private $updateDate;

        public const STATUS_RUNNING = 'running';
        public const STATUS_DONE = 'done';
        public const STATUS_ERROR = 'error';

        public function __construct(string $trigger)
        {
            $this->trigger = $trigger;
            $this->createDate = new \DateTime();
            $this->updateDate = new \DateTime();
        }

        public function getStatus(): string
        {
            return $this->status;
        }

        public function setStatus(string $v): self
        {
            $this->status = $v;

            return $this;
        }

        public function isRunning(): bool
        {
            return $this->status === self::STATUS_RUNNING;
        }

        public function getProcessed(): int
        {
            return $this->processed;
        }

        public function setProcessed(int $v): self
        {
            $this->processed = $v;

            return $this;
        }

        public function getUpdateDate(): ?\DateTimeInterface
        {
            return $this->updateDate;
        }

        public function setUpdateDate(?\DateTimeInterface $v): self
        {
            $this->updateDate = $v;

            return $this;
        }

        public function getId()
        {
            return $this->id;
        }

        public function getCreateDate(): \DateTimeInterface
        {
            return $this->createDate;
        }

        public function getTrigger(): string
        {
            return $this->trigger;
        }

        public function getTotal(): int
        {
            return $this->total;
        }

        public function setTotal(int $v): self
        {
            $this->total = $v;

            return $this;
        }

        public function getSuccess(): int
        {
            return $this->success;
        }

        public function setSuccess(int $v): self
        {
            $this->success = $v;

            return $this;
        }

        public function getFailure(): int
        {
            return $this->failure;
        }

        public function setFailure(int $v): self
        {
            $this->failure = $v;

            return $this;
        }

        public function isError(): bool
        {
            return $this->error;
        }

        public function setError(bool $v): self
        {
            $this->error = $v;
            if ($v) {
                $this->status = self::STATUS_ERROR;
            }

            return $this;
        }

        public function getMessage(): ?string
        {
            return $this->message;
        }

        public function setMessage(?string $v): self
        {
            $this->message = $v === null ? null : mb_substr($v, 0, 4000);

            return $this;
        }
    }
}
