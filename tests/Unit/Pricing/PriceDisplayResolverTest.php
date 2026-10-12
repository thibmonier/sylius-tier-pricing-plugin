<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\TaxRate;
use Sylius\Component\Taxation\Model\TaxableInterface;
use Sylius\Component\Taxation\Model\TaxCategory;
use Sylius\Component\Taxation\Model\TaxRateInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolver;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolverInterface;

final class PriceDisplayResolverTest extends TestCase
{
    public function testTheConventionFollowsTheRateOfTheVariant(): void
    {
        self::assertSame(PriceDisplayResolverInterface::TAX_INCLUDED, $this->resolver($this->rate(true))->taxConvention($this->variant(), $this->channel('WEB')));
        self::assertSame(PriceDisplayResolverInterface::TAX_EXCLUDED, $this->resolver($this->rate(false))->taxConvention($this->variant(), $this->channel('WEB')));
        // Same in B2B channels: amounts are always shown as stored, like the Sylius prices.
        self::assertSame(PriceDisplayResolverInterface::TAX_INCLUDED, $this->resolver($this->rate(true))->taxConvention($this->variant(), $this->channel('WHOLESALE')));
    }

    public function testUnknownWhenNoZoneNoCategoryOrNoRate(): void
    {
        $withoutZone = new Channel();
        $withoutZone->setCode('WEB');

        self::assertNull($this->resolver($this->rate(false))->taxConvention($this->variant(), $withoutZone));
        self::assertNull($this->resolver($this->rate(false))->taxConvention(new ProductVariant(), $this->channel('WEB')));
        self::assertNull($this->resolver(null)->taxConvention($this->variant(), $this->channel('WEB')));
    }

    public function testTheRateIsResolvedOncePerCategoryAndZone(): void
    {
        $rateResolver = $this->rateResolver($this->rate(true));
        $resolver = new PriceDisplayResolver($rateResolver, [], ['b2c' => false, 'b2b' => true]);
        $variant = $this->variant();
        $channel = $this->channel('WEB');

        for ($i = 0; $i < 5; ++$i) {
            $resolver->taxConvention($variant, $channel);
        }
        self::assertSame(1, $rateResolver->calls);

        $resolver->reset();
        $resolver->taxConvention($variant, $channel);
        self::assertSame(2, $rateResolver->calls);
    }

    public function testThePercentageColumnIsConfiguredPerChannelType(): void
    {
        $resolver = $this->resolver(null);

        self::assertFalse($resolver->showsPercentage($this->channel('WEB')));
        self::assertTrue($resolver->showsPercentage($this->channel('WHOLESALE')));
        self::assertTrue($resolver->isBusinessChannel($this->channel('WHOLESALE')));
        self::assertFalse($resolver->isBusinessChannel($this->channel('WEB')));
    }

    private function resolver(?TaxRateInterface $rate): PriceDisplayResolver
    {
        return new PriceDisplayResolver($this->rateResolver($rate), ['WHOLESALE'], ['b2c' => false, 'b2b' => true]);
    }

    private function rateResolver(?TaxRateInterface $rate): CountingTaxRateResolver
    {
        return new CountingTaxRateResolver($rate);
    }

    private function rate(bool $includedInPrice): TaxRate
    {
        $rate = new TaxRate();
        $rate->setAmount(0.2);
        $rate->setIncludedInPrice($includedInPrice);

        return $rate;
    }

    private function variant(): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setTaxCategory(new TaxCategory());

        return $variant;
    }

    private function channel(string $code): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setDefaultTaxZone(new Zone());

        return $channel;
    }
}

final class CountingTaxRateResolver implements TaxRateResolverInterface
{
    public int $calls = 0;

    public function __construct(private readonly ?TaxRateInterface $rate)
    {
    }

    public function resolve(TaxableInterface $taxable, array $criteria = []): ?TaxRateInterface
    {
        ++$this->calls;

        return isset($criteria['zone']) ? $this->rate : null;
    }
}
