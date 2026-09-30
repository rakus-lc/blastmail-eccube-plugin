<?php

namespace Plugin\BlastmailSync\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Customer;
use Plugin\BlastmailSync\Entity\Config;
use Plugin\BlastmailSync\Entity\SyncLog;
use Plugin\BlastmailSync\Entity\SyncState;
use Plugin\BlastmailSync\Repository\ConfigRepository;
use Plugin\BlastmailSync\Repository\SyncStateRepository;
use Psr\Log\LoggerInterface;

/**
 * 会員 → blastmail 読者 の同期。
 *
 * 挙動の根拠: blastmail 公開 API（api.bme.jp/rest/1.0）の実測（2026-09-03。2026-09-24 に実アカウントで再確認）。
 *
 * 状態（オプトイン／オプトアウト）の扱いが要点:
 *  - 一括 upsert の CSV には**状態列を含めない**。blastmail は CSV にある列だけ更新するため、
 *    状態列を送らなければ読者本人の解除・配信停止・エラー停止はそのまま残る。
 *    逆に状態列を空欄で送ると「配信中」に戻ってしまうので、空欄では絶対に送らない
 *  - EC-CUBE 側のオプトアウトは握りつぶさない。**状態を変えるべき会員だけを別の CSV で送る**
 *    （メールアドレス列＋状態列の 2 列。決定表は decideStatus を参照）
 *  - EC-CUBE 発のオプトアウトは「配信停止」にマップし、読者自身の「解除」とは区別する
 *  - 読者本人の「解除」とエラー停止は上書きしない（方針「意図マージ・あと勝ち」の解除だけが例外）。
 *    対象は毎回 blastmail からエクスポートして把握する
 *
 * 大規模対応: 会員は 1,000 件ずつ読んで EntityManager を clear、CSV は batch_size 行ごとに分割送信。
 */
class SyncService
{
    public const STATUS_SEND = '配信中';
    public const STATUS_SENDSTOP = '配信停止';
    public const STATUS_CANCEL = '解除';
    public const STATUS_ERRORSTOP = 'エラー停止';

    /** 管理画面のボタンから同期を許す対象会員数の上限。超えたらコンソール実行を案内する */
    public const UI_DIRECT_LIMIT = 20000;

    /** 会員読み込みのチャンク幅 */
    private const CHUNK = 1000;

    private const STATUS_DEFINE_ID = 'status';

    public function __construct(
        private BlastmailClient $client,
        private CustomerSource $source,
        private ConfigRepository $configRepository,
        private SyncStateRepository $stateRepository,
        private EntityManagerInterface $em,
        private EccubeConfig $eccubeConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 同期を実行する。設定が差分モードなら差分、$forceFull なら全件。
     *
     * @param callable|null $progress fn(int $processed, int $total, string $phase): void
     */
    public function syncAll(string $trigger = 'manual', bool $forceFull = false, ?callable $progress = null): SyncLog
    {
        $Config = $this->configRepository->get();
        $diff = $Config->isDiffMode() && !$forceFull;
        $log = new SyncLog($trigger.($diff ? ':diff' : ':full'));
        $log->setStatus(SyncLog::STATUS_RUNNING);

        $lock = $this->acquireLock();
        if ($lock === null) {
            $log->setError(true)->setMessage('別の同期が実行中です。完了を待ってから再実行してください。');
            $this->em->persist($log);
            $this->em->flush();

            return $log;
        }

        $columns = $this->columns($Config);
        $keys = array_keys($columns);
        $policy = $Config->getStatusPolicy();
        $withdrawSendstop = $Config->getWithdrawAction() === Config::WITHDRAW_SENDSTOP;
        $bounceOn = $Config->isBounceSyncEnabled() && $this->bounceSupported();
        $optoutImportOn = $Config->isOptoutImportEnabled() && $this->source->optInColumn() !== null;
        $batchSize = $Config->getBatchSize();
        $total = $this->source->countActive();
        $this->em->persist($log);
        $this->em->flush();
        $logId = $log->getId();
        $conn = $this->em->getConnection();

        $sent = 0;
        $success = 0;
        $failure = 0;
        $statusSent = 0;
        $statusFailure = 0;
        $protectedSkipped = 0;
        $cancelProtected = [];
        $errorStopSent = 0;
        $details = [];
        $notes = [];
        $targets = 0;
        $processed = 0;

        try {
            $defines = $this->client->defineList();
            $this->assertMappingMatchesDefines($Config, $defines);
            $emailHeader = $columns['email'];
            $statusHeader = $this->statusHeader($defines);

            // blastmail 側の配信状態。email(小文字) => 状態（配信停止／解除／エラー停止。無い読者は「配信中」扱い）。
            // 「blastmail を正とする」以外の方針では最初に 3 状態の読者一覧を取得する（意図マージの判定と、双方向オプトアウト・エラー停止の取り込みに使う）。
            // 性能: 読者数に比例して時間とメモリを使う。大規模で避けたいときは「blastmail を正とする」方針を選ぶ
            $bmCache = null;
            $loadBm = function () use (&$bmCache, &$notes, $emailHeader): void {
                if ($bmCache !== null) {
                    return;
                }
                $bmCache = [];
                foreach ([self::STATUS_SENDSTOP, self::STATUS_CANCEL, self::STATUS_ERRORSTOP] as $st) {
                    try {
                        foreach ($this->client->exportEmailsByStatus($st, $emailHeader) as $em => $_) {
                            $bmCache[strtolower((string) $em)] = $st;
                        }
                    } catch (\Throwable $e) {
                        $notes[] = sprintf('「%s」の読者一覧を取得できませんでした（%s）。この状態の読者は「配信中」として扱います。', $st, $e->getMessage());
                    }
                }
            };
            $bmStatusOf = function (string $email) use (&$bmCache, $loadBm): string {
                $loadBm();

                return $bmCache[strtolower($email)] ?? self::STATUS_SEND;
            };
            // 対象外・退会の「配信停止」送信で使う保護判定（解除・エラー停止は触らない）
            $isProtected = function (string $email) use ($bmStatusOf, $policy): bool {   // 退会・対象外の配信停止で、解除・エラー停止には触らない
                if ($policy === Config::POLICY_SOURCE_INITIAL_ONLY) {
                    return false;
                }
                $st = $bmStatusOf($email);

                return in_array($st, [self::STATUS_ERRORSTOP, self::STATUS_CANCEL], true);
            };
            if ($bounceOn) {
                $loadBm();
                $imported = 0;
                $markedNow = 0;
                foreach ($bmCache as $em => $st) {
                    if ($st === self::STATUS_ERRORSTOP) {
                        $imported++;
                        $markedNow += $this->markBouncedFromBlastmail($em);
                    }
                }
                $notes[] = sprintf('blastmail のエラー停止 %d 件を取り込み、会員 %d 名を宛先不明にした', $imported, $markedNow);
            }
            $optedOut = 0;

            $buffer = [];       // 通常 upsert: cid => ['row'=>..., 'hash'=>..., 'opt'=>bool, 'bm'=>string]
            $statusBuffer = []; // 状態変更: email => 状態
            $optOnly = [];      // 状態変更が不要で、オプト値と blastmail 側状態だけ記録すればよい会員: cid => ['opt'=>bool,'bm'=>string]
            // 状態変更を送る会員: email => [cid, opt, bm]。opt_in / bm_status は状態変更が**成功してから**書く
            // （失敗したまま進めると、その変更は二度と送られなくなる）
            $pendingOpt = [];

            $flushUpsert = function () use (&$buffer, &$sent, &$success, &$failure, &$details, $Config, $defines, $conn) {
                if ($buffer === []) {
                    return;
                }
                $result = $this->client->importAll($this->buildCsv(array_column($buffer, 'row'), $Config, $defines));
                $sent += count($buffer);
                $success += $result['success'];
                $failure += $result['failure'];
                $failedLines = array_map(fn ($d) => $d['lineNumber'], $result['details']);
                foreach ($result['details'] as $d) {
                    if (count($details) < 20) {
                        $details[] = $d;
                    }
                }
                $ok = [];
                $line = 2; // 1 行目はヘッダ
                foreach ($buffer as $cid => $item) {
                    if (!in_array($line, $failedLines, true)) {
                        $ok[$cid] = $item;
                    }
                    $line++;
                }
                $this->upsertStates($conn, $ok);
                $buffer = [];
            };

            $flushStatus = function () use (&$statusBuffer, &$pendingOpt, &$statusSent, &$statusFailure, &$notes, $flushUpsert, $emailHeader, $statusHeader, $Config, $conn) {
                if ($statusBuffer === []) {
                    return;
                }
                // 新規読者を先に登録してから状態を変える
                $flushUpsert();
                $result = $this->client->importAll($this->buildStatusCsv($statusBuffer, $emailHeader, $statusHeader, $Config));
                $statusSent += count($statusBuffer) - $result['failure'];
                $statusFailure += $result['failure'];
                foreach (array_slice($result['details'], 0, 5) as $d) {
                    $notes[] = sprintf('状態変更に失敗 行%d: %s', $d['lineNumber'], $d['message']);
                }
                // 成功した会員だけ opt_in を今回の値に進める（失敗した会員は前回値のまま → 次回再送）
                $failedLines = array_map(fn ($d) => $d['lineNumber'], $result['details']);
                $succeeded = [];
                $line = 2;
                foreach (array_keys($statusBuffer) as $email) {
                    if (!in_array($line, $failedLines, true) && isset($pendingOpt[$email])) {
                        [$cid, $opt, $bm] = $pendingOpt[$email];
                        $succeeded[$cid] = ['opt' => $opt, 'bm' => $bm];
                    }
                    unset($pendingOpt[$email]);
                    $line++;
                }
                $this->updateOptIn($conn, $succeeded);
                $statusBuffer = [];
            };

            $afterId = 0;
            while (true) {
                $chunk = $this->source->findTargetChunk($Config, $afterId, self::CHUNK);
                if ($chunk === []) {
                    break;
                }
                $afterId = end($chunk)->getId();
                $inScope = array_values(array_filter($chunk, fn (Customer $c) => $this->source->isTarget($c, $Config)));
                $rows = $this->source->toRows($inScope, $keys);
                $opts = [];
                $bounced = [];
                foreach ($inScope as $c) {
                    $opts[$c->getId()] = $this->source->isOptIn($c);
                    $bounced[$c->getId()] = $bounceOn && $c->isMailBounced();
                }
                $targets += count($rows);

                $ids = array_map(fn (Customer $c) => $c->getId(), $chunk);
                $states = $this->loadStates($conn, $ids);

                // 同期対象から外れた会員（メルマガ希望を外した等）→ 配信停止
                if ($withdrawSendstop) {
                    foreach ($chunk as $c) {
                        if (!isset($rows[$c->getId()]) && isset($states[$c->getId()])) {
                            $email = $states[$c->getId()]['email'];
                            if (!$isProtected($email)) {
                                $statusBuffer[$email] = self::STATUS_SENDSTOP;
                                $notes[] = sprintf('対象外: %s → 配信停止', $email);
                            } else {
                                $protectedSkipped++;
                            }
                            // 行は消さない。復帰したときにアドレス追跡と「自分が止めた」事実を使う
                            $conn->update('plg_blastmail_sync_state',
                                ['withdrawn' => true, 'bm_status' => $isProtected($email) ? null : self::STATUS_SENDSTOP],
                                ['customer_id' => $c->getId()],
                                ['withdrawn' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
                        }
                    }
                }

                foreach ($rows as $cid => $row) {
                    $hash = $this->rowHash($row, $Config);
                    $state = $states[$cid] ?? null;
                    $prevOpt = $state === null ? null : (bool) $state['opt_in'];
                    $curOpt = $opts[$cid];

                    // メールアドレス変更 → contactID 指定で c15 を書き換え（読者の履歴を維持）
                    $addrChanged = $state !== null && $state['email'] !== $row['email'];
                    if ($addrChanged) {
                        $id = $this->client->findContactIdByEmail($state['email']);
                        if ($id !== null) {
                            $this->client->updateContact($id, ['c15' => $row['email']]);
                            $notes[] = sprintf('アドレス変更: %s → %s', $state['email'], $row['email']);
                        }
                    }
                    // blastmail 側の今の状態（アドレス変更後は旧アドレスで一覧に載っている）
                    $curBm = $policy === Config::POLICY_SOURCE_INITIAL_ONLY ? self::STATUS_SEND : $bmStatusOf($addrChanged ? $state['email'] : $row['email']);
                    $prevBm = $state['bm_status'] ?? null;

                    $rowChanged = $state === null || $state['row_hash'] !== $hash;

                    // 状態の判定
                    $statusQueued = false;
                    $bmAfter = $curBm;
                    $queue = function (string $st) use (&$statusBuffer, &$pendingOpt, &$statusQueued, &$bmAfter, $row, $cid, &$curOpt): void {
                        $statusBuffer[$row['email']] = $st;
                        $pendingOpt[$row['email']] = [$cid, $curOpt, $st];
                        $statusQueued = true;
                        $bmAfter = $st;
                    };
                    if ($bounced[$cid] ?? false) {
                        // 宛先不明はオプトイン／アウトより強い。既にエラー停止なら送らない
                        if ($curBm !== self::STATUS_ERRORSTOP) {
                            $queue(self::STATUS_ERRORSTOP);
                            $errorStopSent++;
                        }
                    } elseif ($addrChanged && $bounceOn && $curBm === self::STATUS_ERRORSTOP) {
                        // 旧アドレスのエラー停止は新アドレスに引き継がない（新アドレスは未検証）。受信可否どおりに戻す
                        $queue($curOpt ? self::STATUS_SEND : self::STATUS_SENDSTOP);
                        $notes[] = sprintf('アドレス変更でエラー停止を解除: %s → %s', $row['email'], $bmAfter);
                    } elseif ($state !== null && ($state['withdrawn'] ?? false)) {
                        // 退会・対象外から復帰した会員。自分が送った「配信停止」を意思として読み戻さず、
                        // EC-CUBE の受信可否どおりに戻す（解除・エラー停止は従来どおり保護）
                        if ($curBm === self::STATUS_CANCEL) {
                            $cancelProtected[] = $row['email'];
                        } elseif ($curBm === self::STATUS_ERRORSTOP) {
                            $protectedSkipped++;
                        } elseif (($curOpt ? self::STATUS_SEND : self::STATUS_SENDSTOP) !== $curBm) {
                            $queue($curOpt ? self::STATUS_SEND : self::STATUS_SENDSTOP);
                            $notes[] = sprintf('退会から復帰: %s → %s', $row['email'], $bmAfter);
                        }
                    } else {
                        $d = $this->decideStatus($prevOpt, $curOpt, $prevBm, $curBm, $policy, $optoutImportOn);
                        if ($d['import']) {
                            // 双方向オプトアウト: blastmail 側の解除・配信停止を EC-CUBE の受信可否へ
                            $this->setOptOut($conn, $cid);
                            $curOpt = false;
                            $optedOut++;
                        }
                        if ($d['status'] !== null) {
                            $queue($d['status']);
                            if ($d['note'] === 'override_cancel') {
                                $notes[] = sprintf('解除を EC-CUBE の新しい意思で上書き: %s → %s', $row['email'], $d['status']);
                            }
                        } elseif ($d['note'] === 'protected_cancel') {
                            $cancelProtected[] = $row['email'];
                        } elseif ($d['note'] === 'protected') {
                            $protectedSkipped++;
                        }
                    }

                    // 通常 upsert。状態変更を送る会員の opt_in / bm_status は「前回値」で書いておき、状態変更の成功後に進める
                    if (!$diff || $rowChanged) {
                        $buffer[$cid] = ['row' => $row, 'hash' => $hash,
                            'opt' => $statusQueued ? ($prevOpt ?? true) : $curOpt,
                            'bm' => $statusQueued ? ($prevBm ?? $curBm) : $bmAfter];
                        if (count($buffer) >= $batchSize) {
                            $flushUpsert();
                        }
                    } elseif (!$statusQueued) {
                        $optOnly[$cid] = ['opt' => $curOpt, 'bm' => $bmAfter];
                    }
                    if ($statusQueued && count($statusBuffer) >= $batchSize) {
                        $flushStatus();
                    }
                }

                $processed += count($chunk);
                $this->updateOptIn($conn, $optOnly);
                $optOnly = [];
                $this->em->clear();
                $conn->update('plg_blastmail_sync_log', ['processed' => $processed, 'total' => $sent + count($buffer), 'update_date' => $this->dbNow($conn)], ['id' => $logId]);
                if ($progress) {
                    $progress($processed, $total, 'scan');
                }
            }
            $flushUpsert();
            $flushStatus();

            // 前回は送ったが、もう本会員でない（退会・削除）会員 → 配信停止
            if ($withdrawSendstop) {
                // withdrawn が立っている行は前回の同期で止め済み。毎回送り直さないよう除く
                $gone = $conn->fetchAllAssociative(
                    'SELECT s.customer_id, s.email FROM plg_blastmail_sync_state s LEFT JOIN dtb_customer c ON c.id = s.customer_id AND c.customer_status_id = 2 WHERE c.id IS NULL AND s.withdrawn = false'
                );
                foreach ($gone as $g) {
                    $protected = $isProtected($g['email']);
                    if (!$protected) {
                        $statusBuffer[$g['email']] = self::STATUS_SENDSTOP;
                        $notes[] = sprintf('退会・削除: %s → 配信停止', $g['email']);
                    } else {
                        $protectedSkipped++;
                    }
                    // 行は消さず退会済みの目印を立てる（復帰時にアドレス追跡と「自分が止めた」事実を使う）
                    $conn->update('plg_blastmail_sync_state',
                        ['withdrawn' => true, 'bm_status' => $protected ? null : self::STATUS_SENDSTOP],
                        ['customer_id' => $g['customer_id']],
                        ['withdrawn' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
                }
                $flushStatus();
            }

            $message = $sent === 0
                ? sprintf('変更なし（対象 %d 名）。', $targets)
                : sprintf('対象 %d 名中 %d 名を送信。成功 %d / 失敗 %d', $targets, $sent, $success, $failure);
            if ($statusSent > 0 || $statusFailure > 0) {
                $message .= sprintf("\n配信状態の変更: %d 名（失敗 %d）", $statusSent, $statusFailure);
            }
            if ($cancelProtected) {
                $shown = array_slice($cancelProtected, 0, 10);
                $message .= sprintf(
                    "\n本人が解除しているため配信を再開しなかった会員: %d 名: %s%s",
                    count($cancelProtected),
                    implode(', ', $shown),
                    count($cancelProtected) > count($shown) ? ' ほか' : ''
                );
            }
            if ($protectedSkipped > 0) {
                $message .= sprintf("\n解除・エラー停止のため状態を変更しなかった会員: %d 名", $protectedSkipped);
            }
            if ($errorStopSent > 0) {
                $message .= sprintf("\n宛先不明のため「エラー停止」として送った会員: %d 名", $errorStopSent);
            }
            if ($optedOut > 0) {
                $message .= sprintf("\nblastmail 側の解除・配信停止を受けて「受け取らない」にした会員: %d 名", $optedOut);
            }
            foreach ($details as $d) {
                $message .= sprintf("\n  行%d: %s (%s)", $d['lineNumber'], $d['message'], mb_substr($d['content'], 0, 80));
            }
            if ($failure > count($details)) {
                $message .= sprintf("\n  ... 他 %d 件", $failure - count($details));
            }
            if ($notes) {
                $message .= "\n".implode("\n", array_slice($notes, 0, 50));
                if (count($notes) > 50) {
                    $message .= sprintf("\n  ... 他 %d 件", count($notes) - 50);
                }
            }
            $conn->update('plg_blastmail_sync_log', [
                'status' => SyncLog::STATUS_DONE, 'total' => $sent, 'success' => $success, 'failure' => $failure + $statusFailure,
                'processed' => $processed, 'message' => mb_substr($message, 0, 4000), 'update_date' => $this->dbNow($conn),
            ], ['id' => $logId]);
            $conn->update('plg_blastmail_sync_config', ['last_sync_at' => $this->dbNow($conn)], ['id' => 1]);
        } catch (\Throwable $e) {
            $this->logger->error('[BlastmailSync] syncAll failed: '.$e->getMessage());
            $conn->update('plg_blastmail_sync_log', [
                'status' => SyncLog::STATUS_ERROR, 'is_error' => 1, 'total' => $sent, 'success' => $success, 'failure' => $failure,
                'processed' => $processed, 'message' => mb_substr($e->getMessage(), 0, 4000), 'update_date' => $this->dbNow($conn),
            ], ['id' => $logId], ['is_error' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        } finally {
            $this->releaseLock($lock);
        }
        $this->em->clear();

        return $this->em->find(SyncLog::class, $logId);
    }

    /**
     * 配信状態をどうするかの決定表。
     *
     * 意図マージ（推奨）の考え方: 前回同期からどちら側が変わったかで新しい意思を決める。
     *  - EC-CUBE 側だけ変わった → EC-CUBE の値を送る。blastmail 側が「解除」でも、前回から変わっていなければ上書きする
     *  - blastmail 側だけ変わった → 触らない。解除・配信停止なら EC-CUBE の受信可否へ取り込む（双方向オプトアウト）
     *  - 両方変わった → 順序が分からないので blastmail 側を採る（停止側を優先）
     *  - エラー停止は常に保護（宛先の事実。意思では覆らない）
     *
     * @param bool|null   $prevOpt 前回同期時の EC-CUBE 側オプト値（null = 記録なし＝初回）
     * @param bool        $curOpt  今回の EC-CUBE 側オプト値
     * @param string|null $prevBm  前回同期時の blastmail 側状態（null = 記録なし）
     * @param string      $curBm   今回の blastmail 側状態（配信中／配信停止／解除／エラー停止）
     *
     * @return array{status: string|null, import: bool, note: string|null} status = 送る状態（null なら送らない）、import = EC-CUBE の受信可否を false にするか
     */
    public function decideStatus(?bool $prevOpt, bool $curOpt, ?string $prevBm, string $curBm, string $policy, bool $optoutImportOn): array
    {
        $r = ['status' => null, 'import' => false, 'note' => null];
        if ($policy === Config::POLICY_SOURCE_INITIAL_ONLY) {
            return $r; // blastmail 側が正。新規は blastmail 既定の「配信中」で登録される
        }
        if ($curBm === self::STATUS_ERRORSTOP) {
            $r['note'] = 'protected';

            return $r;
        }
        $ecWanted = $curOpt ? self::STATUS_SEND : self::STATUS_SENDSTOP;

        if ($policy === Config::POLICY_SOURCE_AUTHORITATIVE) {
            // 読者本人の「解除」はどの方針でも覆さない（2026-09-24 決定。P-4）
            if ($curBm === self::STATUS_CANCEL) {
                $r['import'] = $optoutImportOn && $curOpt;
                $r['note'] = 'protected_cancel';
            } elseif ($ecWanted !== $curBm) {
                $r['status'] = $ecWanted;
            }

            return $r;
        }

        // 意図マージ（安全）: 読者本人の「解除」は誰の操作でも上書きしない。
        // 双方向オプトアウトが有効なら、EC-CUBE 側の受信可否を「受け取らない」に揃える（自己修復）
        if ($policy === Config::POLICY_INTENT_MERGE_SAFE && $curBm === self::STATUS_CANCEL) {
            $r['import'] = $optoutImportOn && $curOpt;
            $r['note'] = 'protected_cancel';

            return $r;
        }

        // 意図マージ
        $ecChanged = $prevOpt !== null && $prevOpt !== $curOpt;
        $bmChanged = $prevBm !== null && $prevBm !== $curBm;
        $bmStopped = in_array($curBm, [self::STATUS_CANCEL, self::STATUS_SENDSTOP], true);

        if ($ecChanged && !$bmChanged) {
            if ($ecWanted !== $curBm) {
                $r['status'] = $ecWanted;
                $r['note'] = $curBm === self::STATUS_CANCEL ? 'override_cancel' : null;
            }

            return $r;
        }
        if ($ecChanged && $bmChanged) {
            // 両方変わった。停止側を優先する
            if ($curOpt && $bmStopped) {
                $r['import'] = $optoutImportOn;
                $r['note'] = 'conflict_bm_wins';
            } elseif (!$curOpt && $curBm === self::STATUS_SEND) {
                $r['status'] = self::STATUS_SENDSTOP;
                $r['note'] = 'conflict_stop_wins';
            }

            return $r;
        }
        // EC-CUBE 側は変わっていない（初回を含む）
        if ($prevOpt === null && !$curOpt && $curBm === self::STATUS_SEND) {
            $r['status'] = self::STATUS_SENDSTOP; // 初回: オプトアウトは反映する。オプトインは blastmail 現況を尊重

            return $r;
        }
        if ($curOpt && $bmStopped && $optoutImportOn) {
            $r['import'] = true;
            $r['note'] = 'import_optout';
        }

        return $r;
    }

    /**
     * 会員 1 件を upsert（登録・編集イベント用）。対象外の会員は何もしない。
     */
    public function syncOne(Customer $Customer, string $trigger): ?SyncLog
    {
        $Config = $this->configRepository->get();
        if (!$this->source->isTarget($Customer, $Config)) {
            // 同期対象から外れた会員（管理画面で会員ステータスを「退会」にした等）は配信停止にする。
            // EC-CUBE は退会にした時点でアドレスをダミー（@dummy.dummy）に置き換えるため、
            // 現在のアドレスではなく前回同期したアドレスで止める。一度も同期していない会員は何もしない。
            $state = $this->stateRepository->find($Customer->getId());

            return $state === null ? null : $this->stopOne($state->getEmail(), $trigger, $Customer->getId());
        }
        $log = new SyncLog($trigger);
        $log->setTotal(1);
        try {
            $row = $this->source->toRow($Customer);
            $curOpt = $this->source->isOptIn($Customer);
            $state = $this->stateRepository->find($Customer->getId());
            $prevOpt = $state === null ? null : $state->isOptIn();
            if ($state && $state->getEmail() !== $row['email']) {
                $id = $this->client->findContactIdByEmail($state->getEmail());
                if ($id !== null) {
                    $this->client->updateContact($id, ['c15' => $row['email']]);
                }
            }
            $defines = $this->client->defineList();
            $result = $this->client->importAll($this->buildCsv([$row], $Config, $defines));
            $log->setSuccess($result['success'])->setFailure($result['failure']);
            $msg = $Customer->getEmail().' '.sprintf('成功 %d / 失敗 %d', $result['success'], $result['failure'])
                .($result['details'] ? ' '.$result['details'][0]['message'] : '');

            // 配信状態
            if ($result['failure'] === 0) {
                $policy = $Config->getStatusPolicy();
                $bounceOn = $Config->isBounceSyncEnabled() && $this->bounceSupported();
                $optoutImportOn = $Config->isOptoutImportEnabled() && $this->source->optInColumn() !== null;
                $curBm = self::STATUS_SEND;
                if ($policy !== Config::POLICY_SOURCE_INITIAL_ONLY) {
                    $curBm = $this->client->findContactStatusByEmail($row['email']) ?? self::STATUS_SEND;
                }
                $prevBm = $state?->getBmStatus();
                $addrChanged = $state !== null && $state->getEmail() !== $row['email'];
                $status = null;
                $note = null;
                if ($bounceOn && $Customer->isMailBounced()) {
                    $status = $curBm === self::STATUS_ERRORSTOP ? null : self::STATUS_ERRORSTOP;
                } elseif ($addrChanged && $bounceOn && $curBm === self::STATUS_ERRORSTOP) {
                    $status = $curOpt ? self::STATUS_SEND : self::STATUS_SENDSTOP;
                } elseif ($state !== null && $state->isWithdrawn()) {
                    // 退会・対象外から復帰した会員。受信可否どおりに戻す（解除・エラー停止は保護）
                    if (in_array($curBm, [self::STATUS_CANCEL, self::STATUS_ERRORSTOP], true)) {
                        $note = $curBm === self::STATUS_CANCEL ? 'protected_cancel' : 'protected';
                    } else {
                        $wanted = $curOpt ? self::STATUS_SEND : self::STATUS_SENDSTOP;
                        $status = $wanted === $curBm ? null : $wanted;
                        $msg .= ' / 退会から復帰';
                    }
                } else {
                    $d = $this->decideStatus($prevOpt, $curOpt, $prevBm, $curBm, $policy, $optoutImportOn);
                    if ($d['import']) {
                        $this->setOptOut($this->em->getConnection(), $Customer->getId());
                        $curOpt = false;
                        $msg .= ' / blastmail 側が「'.$curBm.'」のため受信可否を「受け取らない」に変更';
                    }
                    $status = $d['status'];
                    $note = $d['note'];
                }
                if ($status !== null) {
                    $this->client->setContactStatusByEmail($row['email'], $status);
                    $msg .= ' / 配信状態を「'.$status.'」に変更'.($note === 'override_cancel' ? '（解除を新しい意思で上書き）' : '');
                } elseif ($note === 'protected_cancel') {
                    $msg .= ' / 本人が解除しているため配信を再開しませんでした';
                } elseif ($note === 'protected') {
                    $msg .= ' / エラー停止（または保護対象の解除）のため配信状態は変更せず';
                }
                $state = $state ?? new SyncState($Customer->getId());
                $state->setEmail($row['email'])->setRowHash($this->rowHash($row, $Config))->setOptIn($curOpt)
                    ->setBmStatus($status ?? $curBm)->setSyncedAt(new \DateTime())->setWithdrawn(false);
                $this->em->persist($state);
            }
            $log->setMessage($msg);
        } catch (\Throwable $e) {
            $log->setError(true)->setMessage($Customer->getEmail().' '.$e->getMessage());
            $this->logger->error('[BlastmailSync] syncOne failed: '.$e->getMessage());
        }
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    /**
     * 退会・削除時: blastmail 側の読者を「配信停止」にする。
     */
    public function stopOne(string $email, string $trigger, ?int $customerId = null): ?SyncLog
    {
        $Config = $this->configRepository->get();
        // 同期状態は消さない。退会済みの目印を立てて、前回同期したアドレスを残す
        // （復帰したときにアドレス追跡が生き、自分が送った配信停止を意思として読み戻さない）
        if ($customerId !== null && ($state = $this->stateRepository->find($customerId))) {
            $state->setWithdrawn(true)->setBmStatus(self::STATUS_SENDSTOP);
            $this->em->persist($state);
            $this->em->flush();
        }
        if ($Config->getWithdrawAction() !== Config::WITHDRAW_SENDSTOP || $email === '') {
            return null;
        }
        $log = new SyncLog($trigger);
        $log->setTotal(1);
        try {
            if (in_array($this->client->findContactStatusByEmail($email), [self::STATUS_CANCEL, self::STATUS_ERRORSTOP], true)) {
                $log->setSuccess(0)->setFailure(0)->setMessage($email.' は解除・エラー停止のため変更しませんでした。');
            } else {
                $done = $this->client->setContactStatusByEmail($email, self::STATUS_SENDSTOP);
                $log->setSuccess($done ? 1 : 0)->setFailure($done ? 0 : 1);
                $log->setMessage($email.($done ? ' を配信停止にしました。' : ' は blastmail に未登録でした。'));
            }
        } catch (\Throwable $e) {
            $log->setError(true)->setMessage($email.' '.$e->getMessage());
            $this->logger->error('[BlastmailSync] stopOne failed: '.$e->getMessage());
        }
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    /**
     * 双方向オプトアウト: 会員の受信可否を「受け取らない」にする（blastmail 側の解除・配信停止を受けて）。
     * 受信可否の列は公式プラグインの mailmaga_flg か本プラグインの blastmail_opt_in。型は boolean／整数の両方に対応。
     */
    public function setOptOut(Connection $conn, int $customerId): void
    {
        $col = $this->source->optInColumn();
        if ($col === null) {
            return;
        }
        if ($this->optInColumnIsBool === null) {
            $this->optInColumnIsBool = true;
            try {
                $this->optInColumnIsBool = $conn->createSchemaManager()->listTableColumns('dtb_customer')[$col]->getType()->getName() === 'boolean';
            } catch (\Throwable $e) {
            }
        }
        $conn->executeStatement("UPDATE dtb_customer SET $col = ".($this->optInColumnIsBool ? 'FALSE' : '0').' WHERE id = ?', [$customerId]);
    }

    private ?bool $optInColumnIsBool = null;

    /** 会員の宛先状態（blastengine 連携プラグインが会員に足す列）を扱えるか */
    public function bounceSupported(): bool
    {
        return method_exists(Customer::class, 'isMailBounced');
    }

    /**
     * blastmail で「エラー停止」の読者を、同じアドレスの会員の宛先状態（宛先不明）に反映する。
     * 既に同じアドレスで宛先不明の会員は触らない。
     *
     * @return int 反映した会員数
     */
    public function markBouncedFromBlastmail(string $email): int
    {
        if ($email === '' || !$this->bounceSupported()) {
            return 0;
        }
        $conn = $this->em->getConnection();

        return (int) $conn->executeStatement(
            'UPDATE dtb_customer SET mail_bounce_status = 1, mail_bounce_email = email, mail_bounce_at = ?, mail_bounce_source = ?, mail_bounce_message = ?
             WHERE LOWER(email) = LOWER(?) AND NOT (mail_bounce_status = 1 AND LOWER(COALESCE(mail_bounce_email, \'\')) = LOWER(email))',
            [$conn->convertToDatabaseValue(new \DateTime(), 'datetimetz'), 'blastmail', 'blastmail でエラー停止', $email]
        );
    }

    /** 差分状態を捨てる（次回は全件送る） */
    public function resetState(): void
    {
        $this->stateRepository->clearAll();
    }

    /**
     * マッピングした項目表示名が blastmail 側に「使用中」項目として存在するか検証する。
     *
     * @throws BlastmailApiException 未知の項目名がある場合
     */
    public function assertMappingMatchesDefines(Config $Config, ?array $defines = null): void
    {
        $defines = $defines ?? $this->client->defineList();
        if ($defines === []) {
            return; // 一覧が取れない環境では検証を諦めて送る
        }
        $names = array_map(fn ($d) => $d['name'], array_filter($defines, fn ($d) => $d['uses']));
        $unknown = array_diff(array_values($this->columns($Config)), $names);
        if ($unknown !== []) {
            throw new BlastmailApiException(sprintf(
                'blastmail に存在しない（または未使用の）項目名がマッピングされています: %s。blastmail 管理画面「読者項目の設定」で作成するか、設定画面で表示名を合わせてください。使用中の項目: %s',
                implode(', ', $unknown), implode(', ', $names)
            ), 0);
        }
    }

    /** 送信する行を組み立てる（プレビュー・dry-run 用。全件をメモリに載せるので大規模では使わない）。会員 ID => 行 */
    public function buildRows(Config $Config, int $limit = 0): array
    {
        $rows = [];
        $afterId = 0;
        while (true) {
            $chunk = $this->source->findTargetChunk($Config, $afterId, self::CHUNK);
            if ($chunk === []) {
                break;
            }
            $afterId = end($chunk)->getId();
            $targets = array_values(array_filter($chunk, fn (Customer $c) => $this->source->isTarget($c, $Config)));
            $rows += $this->source->toRows($targets, array_keys($this->columns($Config)));
            $this->em->clear();
            if ($limit > 0 && count($rows) >= $limit) {
                return array_slice($rows, 0, $limit, true);
            }
        }

        return $rows;
    }

    /** 送信列: 項目キー => blastmail 表示名（email は必ず先頭） */
    private function columns(Config $Config): array
    {
        $mapping = $Config->getMapping();
        $columns = ['email' => $mapping['email'] ?? 'E-Mail'];
        foreach (array_keys(CustomerSource::fieldCatalog()) as $key) {
            if (!empty($mapping[$key])) {
                $columns[$key] = $mapping[$key];
            }
        }

        return $columns;
    }

    /** blastmail 側の状態列の表示名（既定「状態」） */
    private function statusHeader(?array $defines): string
    {
        foreach ($defines ?? [] as $d) {
            if ($d['id'] === self::STATUS_DEFINE_ID) {
                return $d['name'];
            }
        }

        return '状態';
    }

    /**
     * blastmail 一括インポート用 CSV（通常の upsert）。
     * ヘッダは blastmail 側の項目表示名。BOM なし・LF・全項目クォート。**状態列は含めない**。
     */
    public function buildCsv(array $rows, Config $Config, ?array $defines = null): string
    {
        $columns = $this->columns($Config);
        $lines = [self::csvLine(array_values($columns))];
        foreach ($rows as $row) {
            $lines[] = self::csvLine(array_map(fn ($k) => $row[$k] ?? '', array_keys($columns)));
        }

        return $this->encode(implode("\n", $lines)."\n", $Config);
    }

    /**
     * 配信状態だけを変える CSV（メールアドレス列 ＋ 状態列の 2 列）。
     *
     * @param array<string,string> $statuses email => 状態
     */
    public function buildStatusCsv(array $statuses, string $emailHeader, string $statusHeader, Config $Config): string
    {
        $lines = [self::csvLine([$emailHeader, $statusHeader])];
        foreach ($statuses as $email => $status) {
            $lines[] = self::csvLine([$email, $status]);
        }

        return $this->encode(implode("\n", $lines)."\n", $Config);
    }

    private function encode(string $csv, Config $Config): string
    {
        $charset = strtoupper($Config->getCsvCharset());
        if ($charset !== 'UTF-8') {
            return mb_convert_encoding($csv, $charset === 'SJIS' ? 'SJIS-win' : $charset, 'UTF-8');
        }

        return $csv;
    }

    /** 行の内容ハッシュ（送る列が変われば別物として扱う） */
    private function rowHash(array $row, Config $Config): string
    {
        $cols = $this->columns($Config);
        $subset = array_map(fn ($k) => $row[$k] ?? '', array_keys($cols));

        return hash('sha256', json_encode([$cols, $subset], JSON_UNESCAPED_UNICODE));
    }

    private static function csvLine(array $values): string
    {
        return implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $values));
    }

    // ---- 差分状態（DBAL 直書き。Doctrine の flush を避けて大量件数に耐える） ----

    /** @return array<int, array{email:string, row_hash:string, opt_in:bool}> */
    private function loadStates(Connection $conn, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $rows = $conn->fetchAllAssociative(
            'SELECT customer_id, email, row_hash, opt_in, bm_status, withdrawn FROM plg_blastmail_sync_state WHERE customer_id IN (?)',
            [$customerIds], [Connection::PARAM_INT_ARRAY]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['customer_id']] = [
                'email' => $r['email'],
                'row_hash' => $r['row_hash'],
                'opt_in' => in_array($r['opt_in'], [true, 't', 1, '1'], true),
                'bm_status' => $r['bm_status'] ?? null,
                'withdrawn' => in_array($r['withdrawn'] ?? false, [true, 't', 1, '1'], true),
            ];
        }

        return $out;
    }

    /** @param array<int, array{row:array, hash:string, opt:bool, bm:?string}> $items */
    private function upsertStates(Connection $conn, array $items): void
    {
        if ($items === []) {
            return;
        }
        $isMysql = str_contains(strtolower(get_class($conn->getDatabasePlatform())), 'mysql');
        $now = $this->dbNow($conn);
        foreach (array_chunk($items, 500, true) as $part) {
            $values = [];
            $params = [];
            $types = [];
            foreach ($part as $cid => $item) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $cid, $item['row']['email'], $item['hash'], $item['opt'], $item['bm'] ?? null, $now, false);
                array_push($types, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::STRING,
                    \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING,
                    \Doctrine\DBAL\ParameterType::BOOLEAN);
            }
            $sql = 'INSERT INTO plg_blastmail_sync_state (customer_id, email, row_hash, opt_in, bm_status, synced_at, withdrawn) VALUES '.implode(', ', $values);
            $sql .= $isMysql
                ? ' ON DUPLICATE KEY UPDATE email = VALUES(email), row_hash = VALUES(row_hash), opt_in = VALUES(opt_in), bm_status = VALUES(bm_status), synced_at = VALUES(synced_at), withdrawn = VALUES(withdrawn)'
                : ' ON CONFLICT (customer_id) DO UPDATE SET email = EXCLUDED.email, row_hash = EXCLUDED.row_hash, opt_in = EXCLUDED.opt_in, bm_status = EXCLUDED.bm_status, synced_at = EXCLUDED.synced_at, withdrawn = EXCLUDED.withdrawn';
            $conn->executeStatement($sql, $params, $types);
        }
    }

    /**
     * 既存の状態行のオプト値と blastmail 側状態だけ更新する（行内容は変わっていないが、判定は済んだ会員）。
     *
     * @param array<int, array{opt:bool, bm:?string}> $items
     */
    private function updateOptIn(Connection $conn, array $items): void
    {
        foreach ($items as $cid => $it) {
            $conn->update('plg_blastmail_sync_state', ['opt_in' => $it['opt'], 'bm_status' => $it['bm'] ?? null], ['customer_id' => $cid],
                ['opt_in' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
    }

    private function dbNow(Connection $conn): string
    {
        return $conn->convertToDatabaseValue(new \DateTime(), 'datetimetz');
    }

    // ---- 同時実行ロック ----

    /** アドバイザリロックのキー（'blastmail_sync' の CRC32。DB 全体で一意ならよい） */
    private const LOCK_KEY = 'blastmail_sync';

    /**
     * 設定「同時実行ロック」に従って排他を取る。取れなければ null。
     *
     * - db（既定・推奨）: DB のアドバイザリロック。**同じ DB を見る限り複数サーバーでも二重実行を防げる**。
     *   接続が切れると解放される（flock のような残留ロックが起きない）
     * - file: flock。単一サーバー向け。DB がアドバイザリロック非対応（SQLite 等）のときの自動フォールバック先
     *
     * @return array{type:string, fp?:resource}|null
     */
    private function acquireLock(): ?array
    {
        $mode = $this->configRepository->get()->getLockMode();
        if ($mode === Config::LOCK_DB) {
            $got = $this->tryDbLock();
            if ($got !== null) {
                return $got ? ['type' => 'db'] : null;
            }
            // アドバイザリロック非対応の DB → ファイルにフォールバック
        }

        $dir = rtrim((string) ($this->eccubeConfig->get('kernel.project_dir') ?? sys_get_temp_dir()), '/').'/var';
        if (!is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }
        $fp = fopen($dir.'/blastmail_sync.lock', 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                fclose($fp);
            }

            return null;
        }

        return ['type' => 'file', 'fp' => $fp];
    }

    /** DB のアドバイザリロックを試す。true=取れた / false=他が保持 / null=この DB では使えない */
    private function tryDbLock(): ?bool
    {
        $conn = $this->em->getConnection();
        $platform = strtolower(get_class($conn->getDatabasePlatform()));
        try {
            if (str_contains($platform, 'postgre')) {
                return (bool) $conn->fetchOne('SELECT pg_try_advisory_lock(?)', [crc32(self::LOCK_KEY)]);
            }
            if (str_contains($platform, 'mysql') || str_contains($platform, 'mariadb')) {
                return $conn->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK_KEY]) == 1;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[BlastmailSync] DB ロックが使えないためファイルロックに切り替えます: '.$e->getMessage());
        }

        return null;
    }

    private function releaseLock(?array $lock): void
    {
        if ($lock === null) {
            return;
        }
        if ($lock['type'] === 'db') {
            $conn = $this->em->getConnection();
            $platform = strtolower(get_class($conn->getDatabasePlatform()));
            try {
                if (str_contains($platform, 'postgre')) {
                    $conn->fetchOne('SELECT pg_advisory_unlock(?)', [crc32(self::LOCK_KEY)]);
                } elseif (str_contains($platform, 'mysql') || str_contains($platform, 'mariadb')) {
                    $conn->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_KEY]);
                }
            } catch (\Throwable $e) {
                // 接続ごと切れていればロックも解放されている
            }

            return;
        }
        if (isset($lock['fp']) && is_resource($lock['fp'])) {
            flock($lock['fp'], LOCK_UN);
            fclose($lock['fp']);
        }
    }
}
