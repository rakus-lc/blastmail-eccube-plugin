<?php

namespace Plugin\BlastmailSync\Command;

use Plugin\BlastmailSync\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 会員を blastmail へ全件同期する（cron 用）。
 *
 *   bin/console blastmail:sync
 *   bin/console blastmail:sync --dry-run   # CSV を標準出力に出すだけ
 */
class SyncCommand extends Command
{
    protected static $defaultName = 'blastmail:sync';
    protected static $defaultDescription = 'Sync EC-CUBE customers to blastmail contacts';

    public function __construct(
        private SyncService $syncService,
        private \Plugin\BlastmailSync\Service\CustomerSource $source,
        private \Plugin\BlastmailSync\Repository\ConfigRepository $configRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '送信せず CSV を表示する')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, '--dry-run で出力する最大行数（0=全件）', 0)
            ->addOption('full', null, InputOption::VALUE_NONE, '差分モード設定でも全件を送る')
            ->addOption('reset-state', null, InputOption::VALUE_NONE, '差分同期の状態を破棄する（次回は全件）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('reset-state')) {
            $this->syncService->resetState();
            $io->success('差分同期の状態を破棄しました。');

            return Command::SUCCESS;
        }
        if ($input->getOption('dry-run')) {
            $Config = $this->configRepository->get();
            $output->write($this->syncService->buildCsv(array_values($this->syncService->buildRows($Config, (int) $input->getOption('limit'))), $Config));

            return Command::SUCCESS;
        }
        $bar = null;
        $log = $this->syncService->syncAll('command', (bool) $input->getOption('full'), function (int $processed, int $total) use (&$bar, $io) {
            if ($bar === null) {
                $bar = $io->createProgressBar(max(1, $total));
                $bar->setFormat(' %current%/%max% 会員 [%bar%] %percent:3s%% 経過 %elapsed:6s%');
            }
            $bar->setProgress(min($processed, max(1, $total)));
        });
        if ($bar) {
            $bar->finish();
            $io->newLine(2);
        }
        if ($log->isError()) {
            $io->error($log->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('対象 %d 件: %s', $log->getTotal(), $log->getMessage()));

        return $log->getFailure() > 0 ? 1 : Command::SUCCESS;
    }
}
