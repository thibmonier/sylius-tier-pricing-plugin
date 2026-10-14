<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

/** The tier price of a variant for given quantities. Amounts in the channel base currency. */
final readonly class TierPriceQuote
{
    public function __construct(
        public TierPriceRuleData $rule,
        /** Tier unit price (already the best of the tier and the current price). */
        public int $unitPrice,
        /** Regular price, before catalog promotions: the base of a percentage. */
        public int $basePrice,
        /** Price without tier (catalog promotions applied). */
        public int $currentPrice,
    ) {
    }

    public function isCheaper(): bool
    {
        return $this->unitPrice < $this->currentPrice;
    }
}
