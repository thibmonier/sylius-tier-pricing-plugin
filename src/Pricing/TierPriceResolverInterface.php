<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

interface TierPriceResolverInterface
{
    /**
     * The rule that applies, among the candidate rules of the product: the one giving the
     * lowest unit price (see calculateUnitPrice()) among the rules applicable to the customer
     * and the quantities. Ties go to the most specific rule (variant over product, customer
     * group over everybody, highest threshold), then to the oldest one. As more rules apply when
     * the quantity grows, the price never goes up with the quantity.
     *
     * @param list<TierPriceRuleData> $rules
     */
    public function resolve(array $rules, TierPriceContext $context, int $basePrice, int $currentPrice, int $minimumPrice = 0): ?TierPriceRuleData;

    /**
     * Unit price given by the rule: a fixed price or `basePrice` minus the percentage, never
     * below `minimumPrice` (channel pricing floor, 0 = none) and never above `currentPrice`
     * (the best of the tier price and the catalog promotion price).
     */
    public function calculateUnitPrice(TierPriceRuleData $rule, int $basePrice, int $currentPrice, int $minimumPrice = 0): int;
}
