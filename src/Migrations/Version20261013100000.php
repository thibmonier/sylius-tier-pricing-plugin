<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tier pricing 1.1: taxon rules and cumulation modes (Schema API: MySQL, MariaDB and
 * PostgreSQL). Existing rules keep their behaviour: no taxon, cumulation `best_of`.
 */
final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Taxon target and cumulation mode of the tier price rules (tmonier/sylius-tier-pricing-plugin 1.1).';
    }

    public function up(Schema $schema): void
    {
        $rule = $schema->getTable('tmonier_tier_price_rule');
        $rule->addColumn('taxon_id', Types::INTEGER, ['notnull' => false]);
        $rule->addColumn('cumulation', Types::STRING, ['length' => 32, 'default' => 'best_of']);
        $rule->addIndex(['taxon_id'], 'IDX_DBFFD354DE13F470');
        $rule->addIndex(['channel_id', 'taxon_id', 'enabled'], 'tmonier_tier_price_rule_taxon_lookup_idx');
        $rule->addForeignKeyConstraint('sylius_taxon', ['taxon_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DBFFD354DE13F470');
    }

    public function down(Schema $schema): void
    {
        $rule = $schema->getTable('tmonier_tier_price_rule');
        $rule->removeForeignKey('FK_DBFFD354DE13F470');
        $rule->dropIndex('tmonier_tier_price_rule_taxon_lookup_idx');
        $rule->dropIndex('IDX_DBFFD354DE13F470');
        $rule->dropColumn('taxon_id');
        $rule->dropColumn('cumulation');
    }
}
