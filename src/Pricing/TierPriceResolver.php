<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

/** Pure: no database, no clock, no container. */
final class TierPriceResolver implements TierPriceResolverInterface
{
    public function resolve(array $rules, TierPriceContext $context, int $basePrice, int $currentPrice, int $minimumPrice = 0): ?TierPriceRuleData
    {
        $best = null;
        $bestPrice = null;
        foreach ($rules as $rule) {
            if (!$this->applies($rule, $context)) {
                continue;
            }
            $price = $this->calculateUnitPrice($rule, $basePrice, $currentPrice, $minimumPrice);
            // Best price wins; specificity only breaks ties.
            if (null === $best || $price < $bestPrice || ($price === $bestPrice && $this->rank($rule) > $this->rank($best))) {
                $best = $rule;
                $bestPrice = $price;
            }
        }

        return $best;
    }

    public function calculateUnitPrice(TierPriceRuleData $rule, int $basePrice, int $currentPrice, int $minimumPrice = 0): int
    {
        $price = TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE === $rule->type
            ? $rule->value
            : (int) round($basePrice * (10000 - min(10000, max(0, $rule->value))) / 10000, 0, \PHP_ROUND_HALF_UP);

        return min(max($price, $minimumPrice, 0), $currentPrice);
    }

    private function applies(TierPriceRuleData $rule, TierPriceContext $context): bool
    {
        if (null !== $rule->variantId && $rule->variantId !== $context->variantId) {
            return false;
        }
        if (null !== $rule->customerGroupId && $rule->customerGroupId !== $context->customerGroupId) {
            return false;
        }
        $quantity = TierPriceRuleInterface::BASIS_PRODUCT === $rule->quantityBasis ? $context->productQuantity : $context->lineQuantity;

        return $quantity >= $rule->minQuantity && $rule->isActiveAt($context->now);
    }

    /** @return array{int, int, int, int} compared element by element: variant, group, threshold, then the oldest rule (stable) */
    private function rank(TierPriceRuleData $rule): array
    {
        return [
            $rule->isVariantScoped() ? 1 : 0,
            null !== $rule->customerGroupId ? 1 : 0,
            $rule->minQuantity,
            -$rule->id,
        ];
    }
}
