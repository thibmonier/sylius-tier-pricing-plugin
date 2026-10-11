<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exactly one target per rule, the one of its scope (two nullable foreign keys rather than a
 * polymorphic target without foreign key). Same SQL on MySQL >= 8.0.16, MariaDB and PostgreSQL;
 * the Schema API has no CHECK constraints (and schema:validate does not compare them).
 */
final class Version20261011300001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Check constraint on the target of the tier price rules (tmonier/sylius-tier-pricing-plugin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tmonier_tier_price_rule ADD CONSTRAINT tmonier_tier_price_rule_target_chk CHECK ((scope = 'product' AND product_id IS NOT NULL AND variant_id IS NULL) OR (scope = 'variant' AND variant_id IS NOT NULL AND product_id IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tmonier_tier_price_rule DROP CONSTRAINT tmonier_tier_price_rule_target_chk');
    }
}
