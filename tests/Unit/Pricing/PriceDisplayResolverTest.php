<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\TaxRate;
use Sylius\Component\Taxation\Calculator\DefaultCalculator;
use Sylius\Component\Taxation\Model\TaxableInterface;
use Sylius\Component\Taxation\Model\TaxRateInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolver;

final class PriceDisplayResolverTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, int}> */
    public static function cases(): iterable
    {
        yield 'B2C, rate not included: tax added' => [false, false, 1200];
        yield 'B2C, rate included: shown as is' => [false, true, 1000];
        yield 'B2B, rate not included: shown as is' => [true, false, 1000];
        yield 'B2B, rate included: tax removed' => [true, true, 833];
    }

    #[DataProvider('cases')]
    public function testTheAmountShownDependsOnTheChannelAndTheRate(bool $business, bool $includedInPrice, int $expected): void
    {
        $rate = new TaxRate();
        $rate->setAmount(0.2);
        $rate->setIncludedInPrice($includedInPrice);
        $rate->setCalculator('default');

        self::assertSame($expected, $this->resolver($rate)->displayAmount(new ProductVariant(), $this->channel($business ? 'WHOLESALE' : 'WEB'), 1000));
    }

    public function testWithoutRateOrZoneTheAmountIsUnchanged(): void
    {
        self::assertSame(1000, $this->resolver(null)->displayAmount(new ProductVariant(), $this->channel('WEB'), 1000));

        $channel = new Channel();
        $channel->setCode('WEB');
        $rate = new TaxRate();
        $rate->setAmount(0.2);
        self::assertSame(1000, $this->resolver($rate)->displayAmount(new ProductVariant(), $channel, 1000));
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
        $rateResolver = new class($rate) implements TaxRateResolverInterface {
            public function __construct(private readonly ?TaxRateInterface $rate)
            {
            }

            public function resolve(TaxableInterface $taxable, array $criteria = []): ?TaxRateInterface
            {
                return isset($criteria['zone']) ? $this->rate : null;
            }
        };

        return new PriceDisplayResolver($rateResolver, new DefaultCalculator(), ['WHOLESALE'], ['b2c' => false, 'b2b' => true]);
    }

    private function channel(string $code): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setDefaultTaxZone(new Zone());

        return $channel;
    }
}
