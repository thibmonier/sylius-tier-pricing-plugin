<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Taxation\Calculator\CalculatorInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;

final class PriceDisplayResolver implements PriceDisplayResolverInterface
{
    /**
     * @param list<string> $businessChannelCodes
     * @param array{b2c: bool, b2b: bool} $percentageColumn
     */
    public function __construct(
        private readonly TaxRateResolverInterface $taxRateResolver,
        private readonly CalculatorInterface $taxCalculator,
        private readonly array $businessChannelCodes,
        private readonly array $percentageColumn,
    ) {
    }

    public function isBusinessChannel(ChannelInterface $channel): bool
    {
        return in_array($channel->getCode(), $this->businessChannelCodes, true);
    }

    public function showsPercentage(ChannelInterface $channel): bool
    {
        return $this->percentageColumn[$this->isBusinessChannel($channel) ? 'b2b' : 'b2c'];
    }

    public function displayAmount(ProductVariantInterface $variant, ChannelInterface $channel, int $amount): int
    {
        $zone = $channel->getDefaultTaxZone();
        $rate = null === $zone ? null : $this->taxRateResolver->resolve($variant, ['zone' => $zone]);
        if (null === $rate) {
            return $amount;
        }

        $tax = (int) round($this->taxCalculator->calculate((float) $amount, $rate));
        if ($this->isBusinessChannel($channel)) {
            return $rate->isIncludedInPrice() ? $amount - $tax : $amount;
        }

        return $rate->isIncludedInPrice() ? $amount : $amount + $tax;
    }
}
