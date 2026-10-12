<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PostgreSQL only: the 1.0 migration created the rule id as an IDENTITY (DBAL 4) or SERIAL
 * (DBAL 3) column, whereas the ORM mapping uses a sequence (like Sylius): a plain integer column
 * fed by `tmonier_tier_price_rule_id_seq`. Existing ids are kept; the sequence restarts after the
 * highest one. Nothing to do on MySQL and MariaDB.
 */
final class Version20261013100002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PostgreSQL: sequence-based ids for the tier price rules (tmonier/sylius-tier-pricing-plugin 1.1).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->write('Not PostgreSQL: nothing to do.');

            return;
        }

        // Identity first (DROP DEFAULT is refused on an identity column), then the SERIAL default.
        $this->addSql('ALTER TABLE tmonier_tier_price_rule ALTER id DROP IDENTITY IF EXISTS');
        $this->addSql('ALTER TABLE tmonier_tier_price_rule ALTER id DROP DEFAULT');
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS tmonier_tier_price_rule_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("SELECT setval('tmonier_tier_price_rule_id_seq', COALESCE((SELECT MAX(id) FROM tmonier_tier_price_rule), 0) + 1, false)");
    }

    public function down(Schema $schema): void
    {
        // The 1.0 column worked too: nothing to restore.
    }
}
