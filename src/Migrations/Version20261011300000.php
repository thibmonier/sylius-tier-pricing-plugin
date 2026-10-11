<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261011300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tier price rule table (tmonier/sylius-tier-pricing-plugin).';
    }

    public function up(Schema $schema): void
    {
        $rule = $schema->createTable('tmonier_tier_price_rule');
        $rule->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $rule->addColumn('channel_id', Types::INTEGER);
        $rule->addColumn('customer_group_id', Types::INTEGER, ['notnull' => false]);
        $rule->addColumn('product_id', Types::INTEGER, ['notnull' => false]);
        $rule->addColumn('variant_id', Types::INTEGER, ['notnull' => false]);
        $rule->addColumn('scope', Types::STRING, ['length' => 16]);
        $rule->addColumn('quantity_basis', Types::STRING, ['length' => 16]);
        $rule->addColumn('min_quantity', Types::INTEGER);
        $rule->addColumn('type', Types::STRING, ['length' => 32]);
        $rule->addColumn('value', Types::INTEGER);
        $rule->addColumn('enabled', Types::BOOLEAN);
        $rule->addColumn('starts_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $rule->addColumn('ends_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $rule->addColumn('created_at', Types::DATETIME_MUTABLE);
        $rule->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $rule->setPrimaryKey(['id']);
        $rule->addIndex(['channel_id'], 'IDX_DBFFD35472F5A1AA');
        $rule->addIndex(['customer_group_id'], 'IDX_DBFFD354D2919A68');
        $rule->addIndex(['product_id'], 'IDX_DBFFD3544584665A');
        $rule->addIndex(['variant_id'], 'IDX_DBFFD3543B69A9AF');
        $rule->addIndex(['channel_id', 'product_id', 'enabled'], 'tmonier_tier_price_rule_lookup_idx');
        $rule->addIndex(['channel_id', 'variant_id', 'enabled'], 'tmonier_tier_price_rule_variant_lookup_idx');
        $rule->addForeignKeyConstraint('sylius_channel', ['channel_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DBFFD35472F5A1AA');
        $rule->addForeignKeyConstraint('sylius_customer_group', ['customer_group_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DBFFD354D2919A68');
        $rule->addForeignKeyConstraint('sylius_product', ['product_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DBFFD3544584665A');
        $rule->addForeignKeyConstraint('sylius_product_variant', ['variant_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DBFFD3543B69A9AF');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_tier_price_rule');
    }
}
