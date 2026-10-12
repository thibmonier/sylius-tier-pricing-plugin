<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exactly one target per rule, the one of its scope, now with taxon rules. Existing rules (no
 * taxon) satisfy the new constraint. Same SQL on MySQL >= 8.0.19, MariaDB and PostgreSQL.
 */
final class Version20261013100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Check constraint on the target of the tier price rules, with taxon rules (tmonier/sylius-tier-pricing-plugin 1.1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tmonier_tier_price_rule DROP CONSTRAINT tmonier_tier_price_rule_target_chk');
        $this->addSql("ALTER TABLE tmonier_tier_price_rule ADD CONSTRAINT tmonier_tier_price_rule_target_chk CHECK ((scope = 'product' AND product_id IS NOT NULL AND variant_id IS NULL AND taxon_id IS NULL) OR (scope = 'variant' AND variant_id IS NOT NULL AND product_id IS NULL AND taxon_id IS NULL) OR (scope = 'taxon' AND taxon_id IS NOT NULL AND product_id IS NULL AND variant_id IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM tmonier_tier_price_rule WHERE scope = 'taxon'");
        $this->addSql('ALTER TABLE tmonier_tier_price_rule DROP CONSTRAINT tmonier_tier_price_rule_target_chk');
        $this->addSql("ALTER TABLE tmonier_tier_price_rule ADD CONSTRAINT tmonier_tier_price_rule_target_chk CHECK ((scope = 'product' AND product_id IS NOT NULL AND variant_id IS NULL) OR (scope = 'variant' AND variant_id IS NOT NULL AND product_id IS NULL))");
    }
}
