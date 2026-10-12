<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class TmonierSyliusTierPricingExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{b2b_channels: list<string>, percentage_column: array{b2c: bool, b2b: bool}, product_page: bool, cart: bool, group_prices_for_guests: bool, cache_ttl: int, driver: string, resources: array<string, mixed>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_tier_pricing', $config['driver'], $config['resources'], $container);

        foreach (['b2b_channels', 'percentage_column', 'product_page', 'cart', 'group_prices_for_guests', 'cache_ttl'] as $key) {
            $container->setParameter('tmonier_tier_pricing.' . $key, $config[$key]);
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Grids are processed before this extension is loaded: the model class parameter has to
        // exist at prepend time.
        $configs = array_map(
            static fn (array $config): array => isset($config['resources']) ? ['resources' => $config['resources']] : [],
            $container->getExtensionConfig($this->getAlias()),
        );
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(sprintf('tmonier_tier_pricing.model.%s.class', $name), $resource['classes']['model']);
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusTierPricingPlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusTierPricingPlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        // Rules of a product, tagged by product, channel and customer group.
        $container->prependExtensionConfig('framework', [
            'cache' => [
                'pools' => [
                    'cache.tmonier_tier_pricing' => ['adapter' => 'cache.app', 'tags' => true],
                ],
            ],
        ]);

        if ($container->hasExtension('api_platform')) {
            $container->prependExtensionConfig('api_platform', [
                'mapping' => ['paths' => [\dirname(__DIR__, 2) . '/config/api_platform']],
            ]);
        }

        $this->prependDoctrineMigrations($container);
    }

    public function getAlias(): string
    {
        return 'tmonier_sylius_tier_pricing';
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusTierPricingPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusTierPricingPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
