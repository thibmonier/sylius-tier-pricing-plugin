<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRule;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Repository\TierPriceRuleRepository;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_tier_pricing');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('b2b_channels')
                    ->info('Codes of the business-to-business channels: tier prices are shown without taxes there. Every other channel is treated as a consumer (B2C) channel: prices with taxes.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('percentage_column')
                    ->info('Show a "-x %" column in the tier table. Off by default in B2C channels: there, tiers are conditional prices, not price reductions (see README, legal notes).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('b2c')->defaultFalse()->end()
                        ->booleanNode('b2b')->defaultTrue()->end()
                    ->end()
                ->end()
                ->booleanNode('product_page')
                    ->info('Show the tier table on product pages.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('cart')
                    ->info('Show the applied tier and the next tier ("add N more for X per unit") in the cart.')
                    ->defaultTrue()
                ->end()
                ->integerNode('cache_ttl')
                    ->info('Lifetime of the cached rules of a product (seconds); saving a rule invalidates them anyway. 0 disables the cache.')
                    ->defaultValue(3600)
                    ->min(0)
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('tier_price_rule')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(TierPriceRule::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(TierPriceRuleInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(TierPriceRuleRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
