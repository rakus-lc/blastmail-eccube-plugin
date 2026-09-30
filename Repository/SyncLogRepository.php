<?php

namespace Plugin\BlastmailSync\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\BlastmailSync\Entity\SyncLog;

class SyncLogRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncLog::class);
    }

    /** @return SyncLog[] */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }

    /** 実行中のログ（直近 1 件）。古い running は異常終了とみなして対象外 */
    public function findRunning(int $staleMinutes = 30): ?SyncLog
    {
        $since = (new \DateTime())->modify("-{$staleMinutes} minutes");

        return $this->createQueryBuilder('l')
            ->where('l.status = :s')->setParameter('s', SyncLog::STATUS_RUNNING)
            ->andWhere('l.updateDate >= :since')->setParameter('since', $since)
            ->orderBy('l.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
