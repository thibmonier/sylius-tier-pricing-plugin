<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

final class TierPriceRow
{
    public function __construct(
        public readonly int $minQuantity,
        public readonly int $unitPrice,
        /** Saving against the price of one unit, in percent (0 for the first row). */
        public readonly float $percentage,
        /** The threshold counts every variant of the product. */
        public readonly bool $mixAndMatch,
    ) {
    }
}
