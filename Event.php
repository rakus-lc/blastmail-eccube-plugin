<?php

namespace Plugin\BlastmailSync;

use Eccube\Entity\Customer;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\BlastmailSync\Repository\ConfigRepository;
use Plugin\BlastmailSync\Service\SyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 会員のライフサイクルに追従して blastmail を更新する。
 * API 障害が EC-CUBE 側の処理を止めないよう、例外はすべて握ってログに残す。
 */
class Event implements EventSubscriberInterface
{
    public function __construct(
        private SyncService $syncService,
        private ConfigRepository $configRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** 応答後（deferred）に処理するジョブ。[['upsert', customerId, trigger] | ['stop', email, customerId, trigger], ...] */
    private array $deferred = [];

    public static function getSubscribedEvents(): array
    {
        return [
            EccubeEvents::FRONT_ENTRY_ACTIVATE_COMPLETE => 'onCustomerUpsert',       // 会員本登録
            EccubeEvents::ADMIN_CUSTOMER_EDIT_INDEX_COMPLETE => 'onCustomerUpsert',   // 管理画面で会員編集
            EccubeEvents::FRONT_MYPAGE_CHANGE_INDEX_COMPLETE => 'onCustomerUpsert',   // マイページで会員情報編集（受信可否の変更を含む）
            EccubeEvents::FRONT_MYPAGE_WITHDRAW_INDEX_COMPLETE => 'onCustomerStop',   // 退会
            EccubeEvents::ADMIN_CUSTOMER_DELETE_COMPLETE => 'onCustomerStop',         // 管理画面で会員削除
            '@admin/Customer/edit.twig' => 'onAdminCustomerEditTwig',                 // 解除を運営操作で上書きする前の確認
            KernelEvents::TERMINATE => ['onKernelTerminate', -512],                    // 「応答後に送る」の実行タイミング（本体のコミット処理より後）
        ];
    }

    /**
     * 会員編集画面に、解除済みの読者を運営操作で配信再開しようとしたときの確認を仕込む。
     */
    public function onAdminCustomerEditTwig(TemplateEvent $event): void
    {
        $event->addSnippet('@BlastmailSync/admin/customer_edit_js.twig');
    }

    public function onCustomerUpsert(EventArgs $event, string $eventName): void
    {
        $Customer = $event->getArgument('Customer');
        if (!$Customer instanceof Customer || !$this->enabled()) {
            return;
        }
        if ($this->isDeferred()) {
            // 画面の応答を返した後（kernel.terminate）に送る。blastmail の応答待ちで画面を待たせない
            $this->deferred[] = ['upsert', $Customer->getId(), 'event:'.$eventName];

            return;
        }
        try {
            $this->syncService->syncOne($Customer, 'event:'.$eventName);
        } catch (\Throwable $e) {
            $this->logger->error('[BlastmailSync] event upsert failed: '.$e->getMessage());
        }
    }

    public function onCustomerStop(EventArgs $event, string $eventName): void
    {
        $Customer = $event->getArgument('Customer');
        if (!$Customer instanceof Customer || !$this->enabled()) {
            return;
        }
        if ($this->isDeferred()) {
            // 退会処理でメールアドレスが書き換わる実装もあるため、イベント時点の値を取っておく
            $this->deferred[] = ['stop', (string) $Customer->getEmail(), $Customer->getId(), 'event:'.$eventName];

            return;
        }
        try {
            // 退会処理でメールアドレスが書き換わる実装もあるため、イベント時点の値を使う
            $this->syncService->stopOne((string) $Customer->getEmail(), 'event:'.$eventName, $Customer->getId());
        } catch (\Throwable $e) {
            $this->logger->error('[BlastmailSync] event stop failed: '.$e->getMessage());
        }
    }

    /**
     * 応答を返した後に、溜めておいた同期を実行する（PHP-FPM では画面はもう返っている）。
     */
    public function onKernelTerminate(): void
    {
        if ($this->deferred === []) {
            return;
        }
        $jobs = $this->deferred;
        $this->deferred = [];
        foreach ($jobs as $job) {
            try {
                if ($job[0] === 'upsert') {
                    $Customer = $this->em->find(Customer::class, $job[1]);
                    if ($Customer !== null) {
                        $this->syncService->syncOne($Customer, $job[2]);
                    }
                } else {
                    $this->syncService->stopOne($job[1], $job[3], $job[2]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('[BlastmailSync] deferred event sync failed: '.$e->getMessage());
            }
        }
        // EC-CUBE は kernel.terminate で本体のトランザクションをコミットした後、新しいトランザクションを
        // 開いたままにする（コミットされずに接続破棄で捨てられる）。ここでの書き込み（同期ログ・同期状態）が
        // そのトランザクションに乗って消えないよう、残っているネストを明示的にコミットする。
        try {
            $conn = $this->em->getConnection();
            while ($conn->getTransactionNestingLevel() > 0) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            $this->logger->error('[BlastmailSync] deferred commit failed: '.$e->getMessage());
        }
    }

    private function isDeferred(): bool
    {
        try {
            return $this->configRepository->get()->getEventSyncTiming() === \Plugin\BlastmailSync\Entity\Config::EVENT_TIMING_DEFERRED;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function enabled(): bool
    {
        try {
            return $this->configRepository->get()->isEventSyncEnabled();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
