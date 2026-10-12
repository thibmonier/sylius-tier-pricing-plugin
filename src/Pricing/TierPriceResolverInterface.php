<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

interface TierPriceResolverInterface
{
    /**
     * The rule that applies, among the candidate rules of the product. Precedence first: the
     * variant and product rules, else the rules of the deepest taxon of the product, else none
     * (a level counts when one of its rules targets the variant, the customer and the date).
     * Within that level, the rule giving the lowest unit price (see calculateUnitPrice()) among
     * the rules applicable to the quantities (and to the promotions: `skip_if_promoted`). Ties
     * go to the most specific rule (variant over product, customer group over everybody,
     * highest threshold), then to the oldest one. As more rules of a level apply when the
     * quantity grows, the price never goes up with the quantity.
     *
     * @param list<TierPriceRuleData> $rules
     */
    public function resolve(array $rules, TierPriceContext $context, int $basePrice, int $currentPrice, int $minimumPrice = 0): ?TierPriceRuleData;

    /**
     * Unit price given by the rule: a fixed price or `basePrice` (`currentPrice` for an `on_top`
     * rule) minus the percentage, never
     * below `minimumPrice` (channel pricing floor, 0 = none) and never above `currentPrice`
     * (the best of the tier price and the catalog promotion price).
     */
    public function calculateUnitPrice(TierPriceRuleData $rule, int $basePrice, int $currentPrice, int $minimumPrice = 0): int;
}
