<?php

namespace Plugin\BlastmailSync\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\BlastmailSync\Entity\SyncState;

class SyncStateRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncState::class);
    }

    /** @return array<int, SyncState> customer_id => state */
    public function findAllIndexed(): array
    {
        $out = [];
        foreach ($this->findAll() as $s) {
            $out[$s->getCustomerId()] = $s;
        }

        return $out;
    }

    public function clearAll(): void
    {
        $this->createQueryBuilder('s')->delete()->getQuery()->execute();
    }
}
