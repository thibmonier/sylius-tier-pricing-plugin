<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusTierPricingPlugin\Command\ExportTierPriceRulesCommand;
use Tmonier\SyliusTierPricingPlugin\Command\ImportTierPriceRulesCommand;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvExporter;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvImporter;
use Tmonier\SyliusTierPricingPlugin\EventListener\TierPriceCacheInvalidator;
use Tmonier\SyliusTierPricingPlugin\Factory\TierPriceRuleFactory;
use Tmonier\SyliusTierPricingPlugin\Factory\TierPriceRuleFactoryInterface;
use Tmonier\SyliusTierPricingPlugin\Fixture\TierPriceRuleFixture;
use Tmonier\SyliusTierPricingPlugin\Form\Type\TierPriceRuleType;
use Tmonier\SyliusTierPricingPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\CompositeTierPriceExemptionChecker;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceExemptionCheckerInterface;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;
use Tmonier\SyliusTierPricingPlugin\Pricing\CachedTierPriceRuleProvider;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolver;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolverInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculator;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceResolver;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceResolverInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleProviderInterface;
use Tmonier\SyliusTierPricingPlugin\Api\TierPriceTableProvider;
use Tmonier\SyliusTierPricingPlugin\Twig\OrderItemOriginalPriceToDisplayExtension;
use Tmonier\SyliusTierPricingPlugin\Twig\TierPricingExtension;
use Tmonier\SyliusTierPricingPlugin\Validator\ValidTierPriceRuleValidator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('tmonier_tier_pricing.resolver', TierPriceResolver::class);
    $services->alias(TierPriceResolverInterface::class, 'tmonier_tier_pricing.resolver');

    $services
        ->set('tmonier_tier_pricing.rule_provider', CachedTierPriceRuleProvider::class)
        ->args([
            service('tmonier_tier_pricing.repository.tier_price_rule'),
            service('cache.tmonier_tier_pricing'),
            param('tmonier_tier_pricing.cache_ttl'),
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(TierPriceRuleProviderInterface::class, 'tmonier_tier_pricing.rule_provider');

    $services
        ->set('tmonier_tier_pricing.calculator', TierPriceCalculator::class)
        ->args([
            service('tmonier_tier_pricing.rule_provider'),
            service('tmonier_tier_pricing.resolver'),
            service('sylius.calculator.product_variant_price'),
            service('clock'),
        ])
    ;
    $services->alias(TierPriceCalculatorInterface::class, 'tmonier_tier_pricing.calculator');

    $services
        ->set('tmonier_tier_pricing.price_display_resolver', PriceDisplayResolver::class)
        ->args([
            service('sylius.resolver.tax_rate'),
            service('sylius.tax_calculator'),
            param('tmonier_tier_pricing.b2b_channels'),
            param('tmonier_tier_pricing.percentage_column'),
        ])
    ;
    $services->alias(PriceDisplayResolverInterface::class, 'tmonier_tier_pricing.price_display_resolver');

    $services
        ->instanceof(TierPriceExemptionCheckerInterface::class)
        ->tag('tmonier_tier_pricing.exemption_checker')
    ;
    $services
        ->set('tmonier_tier_pricing.exemption_checker', CompositeTierPriceExemptionChecker::class)
        ->args([tagged_iterator('tmonier_tier_pricing.exemption_checker')])
    ;
    $services->alias(TierPriceExemptionCheckerInterface::class, 'tmonier_tier_pricing.exemption_checker');

    // <= 44: after the prices recalculator (50) and the quote price lock of
    // tmonier/sylius-quote-request-plugin (45), before shipping (40) and promotions (20).
    $services
        ->set('tmonier_tier_pricing.order_processor', TierPriceOrderProcessor::class)
        ->args([
            service('tmonier_tier_pricing.calculator'),
            service('tmonier_tier_pricing.exemption_checker'),
            service('sylius.factory.adjustment'),
            service('translator'),
        ])
        ->tag('sylius.order_processor', ['priority' => 44])
    ;

    $services
        ->set('tmonier_tier_pricing.cache_invalidator', TierPriceCacheInvalidator::class)
        ->args([service('tmonier_tier_pricing.rule_provider')])
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush'])
    ;

    $services
        ->set('tmonier_tier_pricing.validator.tier_price_rule', ValidTierPriceRuleValidator::class)
        ->args([service('tmonier_tier_pricing.repository.tier_price_rule')])
        ->tag('validator.constraint_validator', ['alias' => 'tmonier_tier_pricing.validator.tier_price_rule'])
    ;

    $services
        ->set('tmonier_tier_pricing.custom_factory.tier_price_rule', TierPriceRuleFactory::class)
        ->decorate('tmonier_tier_pricing.factory.tier_price_rule')
        ->args([
            service('.inner'),
            service('sylius.repository.product'),
        ])
        ->public()
    ;
    $services->alias(TierPriceRuleFactoryInterface::class, 'tmonier_tier_pricing.factory.tier_price_rule');

    $services
        ->set('tmonier_tier_pricing.form.type.tier_price_rule', TierPriceRuleType::class)
        ->args([
            param('tmonier_tier_pricing.model.tier_price_rule.class'),
            ['sylius'],
        ])
        ->tag('form.type')
    ;

    $services
        ->set('tmonier_tier_pricing.twig.extension', TierPricingExtension::class)
        ->args([
            service('tmonier_tier_pricing.calculator'),
            service('tmonier_tier_pricing.price_display_resolver'),
            service('sylius.context.channel'),
            service('sylius.context.customer'),
            service('sylius.context.cart'),
            service('tmonier_tier_pricing.repository.tier_price_rule'),
            param('tmonier_tier_pricing.product_page'),
            param('tmonier_tier_pricing.cart'),
        ])
        ->tag('twig.extension')
    ;

    $services
        ->set('tmonier_tier_pricing.twig.extension.order_item_original_price_to_display', OrderItemOriginalPriceToDisplayExtension::class)
        ->decorate('sylius_shop.twig.extension.order_item_original_price_to_display', null, 0, \Symfony\Component\DependencyInjection\ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner')])
    ;

    $services
        ->set('tmonier_tier_pricing.listener.admin_menu', AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main'])
    ;

    $services
        ->set('tmonier_tier_pricing.csv.importer', TierPriceRuleCsvImporter::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('tmonier_tier_pricing.factory.tier_price_rule'),
            service('validator'),
            service('tmonier_tier_pricing.rule_provider'),
        ])
    ;
    $services
        ->set('tmonier_tier_pricing.csv.exporter', TierPriceRuleCsvExporter::class)
        ->args([service('doctrine.orm.entity_manager')])
    ;
    $services
        ->set('tmonier_tier_pricing.command.import', ImportTierPriceRulesCommand::class)
        ->args([service('tmonier_tier_pricing.csv.importer')])
        ->tag('console.command')
    ;
    $services
        ->set('tmonier_tier_pricing.command.export', ExportTierPriceRulesCommand::class)
        ->args([service('tmonier_tier_pricing.csv.exporter')])
        ->tag('console.command')
    ;

    $services
        ->set('tmonier_tier_pricing.api.tier_price_table_provider', TierPriceTableProvider::class)
        ->args([
            service('sylius.repository.product_variant'),
            service('tmonier_tier_pricing.calculator'),
            service('tmonier_tier_pricing.price_display_resolver'),
            service('sylius.context.channel'),
            service('sylius.context.customer'),
        ])
        ->tag('api_platform.state_provider')
    ;

    if (class_exists(\Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture::class)) {
        $services
            ->set('tmonier_tier_pricing.fixture.tier_price_rule', TierPriceRuleFixture::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('tmonier_tier_pricing.factory.tier_price_rule'),
            ])
            ->tag('sylius_fixtures.fixture')
        ;
    }
};
