<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tmonier\SyliusTierPricingPlugin\DependencyInjection\Configuration;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRule;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertSame([], $config['b2b_channels']);
        self::assertSame(['b2c' => false, 'b2b' => true], $config['percentage_column']);
        self::assertTrue($config['product_page']);
        self::assertTrue($config['cart']);
        self::assertSame(3600, $config['cache_ttl']);
        $resources = $config['resources'];
        self::assertIsArray($resources);
        self::assertIsArray($resources['tier_price_rule']);
        self::assertIsArray($resources['tier_price_rule']['classes']);
        self::assertSame(TierPriceRule::class, $resources['tier_price_rule']['classes']['model']);
    }

    public function testOverrides(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'b2b_channels' => ['WHOLESALE'],
            'percentage_column' => ['b2c' => true],
            'cache_ttl' => 0,
        ]]);

        self::assertSame(['WHOLESALE'], $config['b2b_channels']);
        self::assertSame(['b2c' => true, 'b2b' => true], $config['percentage_column']);
        self::assertSame(0, $config['cache_ttl']);
    }

    public function testANegativeCacheLifetimeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['cache_ttl' => -1]]);
    }
}
