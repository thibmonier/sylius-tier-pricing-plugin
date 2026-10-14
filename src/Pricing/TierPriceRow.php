<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

final readonly class TierPriceRow
{
    public function __construct(
        public int $minQuantity,
        public int $unitPrice,
        /** Saving against the price of one unit, in percent (0 for the first row). */
        public float $percentage,
        /** The threshold counts every variant of the product. */
        public bool $mixAndMatch,
        /** The threshold counts every product of the taxon of the rule (and of its children). */
        public bool $taxonBasis = false,
    ) {
    }
}
