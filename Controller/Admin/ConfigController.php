<?php

namespace Plugin\BlastmailSync\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\BlastmailSync\Entity\Config;
use Plugin\BlastmailSync\Form\Type\Admin\ConfigType;
use Plugin\BlastmailSync\Repository\ConfigRepository;
use Plugin\BlastmailSync\Service\BlastmailClient;
use Plugin\BlastmailSync\Service\CustomerSource;
use Plugin\BlastmailSync\Service\DefineMatcher;
use Plugin\BlastmailSync\Service\SecretCrypter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ConfigController extends AbstractController
{
    public function __construct(
        private ConfigRepository $configRepository,
        private BlastmailClient $client,
        private SecretCrypter $crypter,
        private CustomerSource $source,
    ) {
    }

    /**
     * @Route("/%eccube_admin_route%/blastmail_sync/config", name="blastmail_sync_admin_config", methods={"GET", "POST"})
     * @Template("@BlastmailSync/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class, $Config, ['mapping' => $Config->getMapping()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // パスワード・API キーは暗号化して保存（空なら既存値を保持。平文で残っている旧値は暗号化へ移行）
            if ('' !== (string) $form->get('password')->getData()) {
                $Config->setPassword($this->crypter->encrypt($form->get('password')->getData()));
            } elseif ($Config->getPassword() && !$this->crypter->isEncrypted($Config->getPassword())) {
                $Config->setPassword($this->crypter->encrypt($Config->getPassword()));
            }
            if ('' !== (string) $form->get('apiKey')->getData()) {
                $Config->setApiKey($this->crypter->encrypt($form->get('apiKey')->getData()));
            } elseif ($Config->getApiKey() && !$this->crypter->isEncrypted($Config->getApiKey())) {
                $Config->setApiKey($this->crypter->encrypt($Config->getApiKey()));
            }
            $mapping = ['email' => $form->get('mapping_email')->getData()];
            foreach (array_keys(CustomerSource::fieldCatalog()) as $key) {
                $mapping[$key] = (string) $form->get('mapping_'.$key)->getData();
            }
            $Config->setMapping($mapping);
            $this->entityManager->persist($Config);
            $this->entityManager->flush();
            $this->addSuccess('登録しました。', 'admin');

            // 「接続テスト」ボタンからの送信: 保存した内容でそのままログインを試す
            if ($request->request->has('test_connection')) {
                $this->runConnectionTest();
            }

            // 「項目名を自動で割り当てる」: 保存した内容で項目一覧を取り、空欄だけを埋めて保存し直す
            if ($request->request->has('auto_map')) {
                $this->runAutoMap($Config);
            }

            return $this->redirectToRoute('blastmail_sync_admin_config');
        }
        if ($form->isSubmitted() && !$form->isValid()) {
            $this->addError('入力内容に誤りがあります。赤字の項目を確認してください。', 'admin');
        }

        // blastmail 側の項目一覧（マッピング入力の候補）。未設定・失敗時は空
        $defines = [];
        $defineError = null;
        if ($this->client->isConfigured()) {
            try {
                $defines = array_values(array_filter($this->client->defineList(), fn ($d) => $d['uses']));
            } catch (\Throwable $e) {
                $defineError = $e->getMessage();
            }
        }

        return [
            'form' => $form->createView(),
            'Config' => $Config,
            'bounceSupported' => method_exists(\Eccube\Entity\Customer::class, 'isMailBounced'),
            'catalog' => CustomerSource::fieldCatalog(),
            'defines' => $defines,
            'defineError' => $defineError,
            'secretWeak' => $this->crypter->isServerSecretWeak(),
            'hasOptInField' => $this->source->hasOptInField(),
            'optInSource' => $this->source->optInSource(),
            'storedPlain' => ($Config->getPassword() && !$this->crypter->isEncrypted($Config->getPassword()))
                || ($Config->getApiKey() && !$this->crypter->isEncrypted($Config->getApiKey())),
            'envOverride' => [
                'username' => '' !== BlastmailClient::env('BLASTMAIL_SYNC_USERNAME'),
                'password' => '' !== BlastmailClient::env('BLASTMAIL_SYNC_PASSWORD'),
                'api_key' => '' !== BlastmailClient::env('BLASTMAIL_SYNC_API_KEY'),
                'base_url' => '' !== BlastmailClient::env('BLASTMAIL_SYNC_BASE_URL'),
            ],
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/blastmail_sync/config/test", name="blastmail_sync_admin_config_test", methods={"POST"})
     */
    public function test(Request $request)
    {
        $this->isTokenValid();
        $this->runConnectionTest();

        return $this->redirectToRoute('blastmail_sync_admin_config');
    }

    private function runConnectionTest(): void
    {
        if (!$this->client->isConfigured()) {
            $this->addError('接続テストを行うには、ログイン ID・パスワード・API キーをすべて入力して保存する必要があります。', 'admin');

            return;
        }
        try {
            $defines = $this->client->testConnection();
            $names = implode(', ', array_map(fn ($d) => $d['name'], array_filter($defines, fn ($d) => $d['uses'])));
            $this->addSuccess(sprintf('接続に成功しました。使用中の項目: %s', $names), 'admin');
        } catch (\Throwable $e) {
            $this->addError('接続に失敗しました: '.$e->getMessage(), 'admin');
        }
    }

    /**
     * blastmail の項目一覧を取り、空欄のマッピングだけを埋めて保存する。入力済みの欄は上書きしない。
     */
    private function runAutoMap(Config $Config): void
    {
        if (!$this->client->isConfigured()) {
            $this->addError('項目名を自動で割り当てるには、ログイン ID・パスワード・API キーをすべて入力して保存する必要があります。', 'admin');

            return;
        }
        try {
            $defines = array_values(array_filter($this->client->defineList(), fn ($d) => $d['uses']));
        } catch (\Throwable $e) {
            $this->addError('項目一覧の取得に失敗しました: '.$e->getMessage(), 'admin');

            return;
        }

        $result = DefineMatcher::autoMap($defines, CustomerSource::fieldCatalog(), $Config->getMapping());
        if ($result['filled']) {
            $Config->setMapping($result['mapping']);
            $this->entityManager->persist($Config);
            $this->entityManager->flush();
            $pairs = [];
            foreach ($result['filled'] as $label => $name) {
                $pairs[] = $label.' → '.$name;
            }
            $this->addSuccess(sprintf('%d 件を自動で割り当てました: %s', count($pairs), implode(' / ', $pairs)), 'admin');
        } else {
            $this->addWarning('自動で割り当てられる項目はありませんでした（空欄の項目に一致する項目名が blastmail 側にありません）。', 'admin');
        }
        if ($result['unmatched']) {
            $this->addWarning(sprintf(
                '一致する項目が見つかりませんでした: %s。blastmail 側の項目名と表記が違う可能性があります（例: 累計／累積）。手で入力してください。',
                implode(', ', $result['unmatched'])
            ), 'admin');
        }
        if ($result['ambiguous']) {
            $this->addWarning(sprintf(
                'blastmail 側に紛らわしい項目名が複数あるため割り当てを見送りました: %s',
                implode(', ', $result['ambiguous'])
            ), 'admin');
        }
    }
}
