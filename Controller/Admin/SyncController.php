<?php

namespace Plugin\BlastmailSync\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Customer;
use Plugin\BlastmailSync\Entity\Config;
use Plugin\BlastmailSync\Repository\ConfigRepository;
use Plugin\BlastmailSync\Repository\SyncLogRepository;
use Plugin\BlastmailSync\Service\BlastmailClient;
use Plugin\BlastmailSync\Service\CustomerSource;
use Plugin\BlastmailSync\Service\SyncService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * blastmail連携 ダッシュボード: 同期実行・同期ログ・blastmail の配信履歴。
 */
class SyncController extends AbstractController
{
    public function __construct(
        private ConfigRepository $configRepository,
        private SyncLogRepository $syncLogRepository,
        private SyncService $syncService,
        private CustomerSource $source,
        private BlastmailClient $client,
    ) {
    }

    /**
     * @Route("/%eccube_admin_route%/blastmail_sync", name="blastmail_sync_admin_index", methods={"GET"})
     * @Template("@BlastmailSync/admin/index.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $targetCount = $this->source->countActive();
        $running = $this->syncLogRepository->findRunning();

        $history = [];
        $historyError = null;
        if ($this->client->isConfigured()) {
            try {
                $history = $this->client->historySearch(10);
            } catch (\Throwable $e) {
                $historyError = $e->getMessage();
            }
        }

        return [
            'Config' => $Config,
            'configured' => $this->client->isConfigured(),
            'targetCount' => $targetCount,
            'directLimit' => SyncService::UI_DIRECT_LIMIT,
            'tooLarge' => $targetCount > SyncService::UI_DIRECT_LIMIT,
            'running' => $running,
            'mappedFields' => array_intersect_key(CustomerSource::fieldCatalog(), array_filter($Config->getMapping())),
            'logs' => $this->syncLogRepository->findRecent(20),
            'history' => $history,
            'historyError' => $historyError,
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/blastmail_sync/run", name="blastmail_sync_admin_run", methods={"POST"})
     */
    public function run(Request $request)
    {
        $this->isTokenValid();
        if ($this->source->countActive() > SyncService::UI_DIRECT_LIMIT) {
            $this->addError(sprintf('対象会員が %s 名を超えるため管理画面からは実行できません。サーバーで bin/console blastmail:sync を実行してください。', number_format(SyncService::UI_DIRECT_LIMIT)), 'admin');

            return $this->redirectToRoute('blastmail_sync_admin_index');
        }
        if ($this->syncLogRepository->findRunning()) {
            $this->addError('同期が実行中です。完了を待ってから再実行してください。', 'admin');

            return $this->redirectToRoute('blastmail_sync_admin_index');
        }
        set_time_limit(0);
        $log = $this->syncService->syncAll('manual', $request->query->getBoolean('full'));
        if ($log->isError()) {
            $this->addError('同期に失敗しました: '.$log->getMessage(), 'admin');
        } else {
            $this->addSuccess(sprintf('同期しました（対象 %d 件、成功 %d 件、失敗 %d 件）。', $log->getTotal(), $log->getSuccess(), $log->getFailure()), 'admin');
        }

        return $this->redirectToRoute('blastmail_sync_admin_index');
    }

    /**
     * 送信する CSV のプレビュー（ダウンロード）。
     *
     * @Route("/%eccube_admin_route%/blastmail_sync/preview.csv", name="blastmail_sync_admin_preview", methods={"GET"})
     */
    public function preview(Request $request): Response
    {
        $Config = $this->configRepository->get();
        // プレビューは先頭 1,000 行まで（大規模でもブラウザで確認できる量に抑える）
        $csv = $this->syncService->buildCsv(array_values($this->syncService->buildRows($Config, 1000)), $Config);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset='.($Config->getCsvCharset() === 'SJIS' ? 'Shift_JIS' : 'UTF-8'),
            'Content-Disposition' => 'attachment; filename="blastmail_import_preview.csv"',
        ]);
    }

    /**
     * 会員編集画面用: その会員が blastmail 側で「解除」になっているかを返す。
     * 「意図マージ・あと勝ち」方針のときだけ true を返し、運営者が受信可否を戻す前に確認を出させる。
     *
     * @Route("/%eccube_admin_route%/blastmail_sync/customer/{id}/cancel_check", name="blastmail_sync_admin_customer_cancel_check", methods={"GET"}, requirements={"id" = "\d+"})
     */
    public function cancelCheck(Customer $Customer): JsonResponse
    {
        $Config = $this->configRepository->get();
        if ($Config->getStatusPolicy() !== Config::POLICY_INTENT_MERGE || !$this->client->isConfigured()) {
            return new JsonResponse(['cancelled' => false]);
        }
        try {
            $cancelled = $this->client->findContactStatusByEmail((string) $Customer->getEmail()) === SyncService::STATUS_CANCEL;
        } catch (\Throwable $e) {
            // API 障害で会員編集を止めない
            return new JsonResponse(['cancelled' => false]);
        }

        return new JsonResponse(['cancelled' => $cancelled]);
    }
}
