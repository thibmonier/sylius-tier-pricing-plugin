<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

interface TierPriceResolverInterface
{
    /**
     * The rule that applies, among the candidate rules of the product: the most specific scope
     * wins (variant over product), then the customer group rule over the rule for everybody,
     * then the highest threshold reached.
     *
     * @param list<TierPriceRuleData> $rules
     */
    public function resolve(array $rules, TierPriceContext $context): ?TierPriceRuleData;

    /**
     * Unit price given by the rule: a fixed price or `basePrice` minus the percentage, never
     * below `minimumPrice` (channel pricing floor, 0 = none) and never above `currentPrice`
     * (the best of the tier price and the catalog promotion price).
     */
    public function calculateUnitPrice(TierPriceRuleData $rule, int $basePrice, int $currentPrice, int $minimumPrice = 0): int;
}
