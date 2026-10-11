<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every test runs in a transaction rolled back at the end (the kernel is not rebooted between
 * requests of a functional test). Fixtures are random: tests create what they need.
 */
trait TierPricingTestTrait
{
    private function beginTestTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    private function rollBackTestTransaction(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    private function catalog(): TestCatalog
    {
        return new TestCatalog(self::getContainer());
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
