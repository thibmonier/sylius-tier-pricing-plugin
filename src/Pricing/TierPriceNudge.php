<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

/** "Add `quantityToAdd` more to pay `unitPrice` per unit". */
final class TierPriceNudge
{
    public function __construct(
        public readonly int $quantityToAdd,
        public readonly int $unitPrice,
        public readonly int $minQuantity,
        public readonly bool $mixAndMatch,
    ) {
    }
}
