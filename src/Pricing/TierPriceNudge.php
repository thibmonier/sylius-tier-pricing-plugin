<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

/** "Add `quantityToAdd` more to pay `unitPrice` per unit". */
final readonly class TierPriceNudge
{
    public function __construct(
        public int $quantityToAdd,
        public int $unitPrice,
        public int $minQuantity,
        public bool $mixAndMatch,
    ) {
    }
}
