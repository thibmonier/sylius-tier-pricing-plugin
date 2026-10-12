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
        foreach ($this->rulesOfTheMostSpecificLevel($rules, $context) as $rule) {
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
        // `on_top`: the percentage is taken off the catalog promotion price.
        $base = TierPriceRuleInterface::CUMULATION_ON_TOP === $rule->cumulation ? $currentPrice : $basePrice;
        $price = TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE === $rule->type
            ? $rule->value
            : (int) round($base * (10000 - min(10000, max(0, $rule->value))) / 10000, 0, \PHP_ROUND_HALF_UP);

        return min(max($price, $minimumPrice, 0), $currentPrice);
    }

    /**
     * Precedence: the rules of the variant and of the product, else those of the deepest taxon
     * of the product that has rules, else none. A level counts as soon as one of its rules
     * targets the variant, the customer and the date, whatever the quantity (so the price never
     * goes up with the quantity) and the promotions (a `skip_if_promoted` product rule does not
     * hand over to a taxon rule).
     *
     * @param list<TierPriceRuleData> $rules
     *
     * @return list<TierPriceRuleData>
     */
    private function rulesOfTheMostSpecificLevel(array $rules, TierPriceContext $context): array
    {
        $levels = [];
        foreach ($rules as $rule) {
            if ($this->targets($rule, $context)) {
                $levels[$rule->isTaxonScoped() ? ($rule->taxonLevel ?? 0) : \PHP_INT_MAX][] = $rule;
            }
        }
        if ([] === $levels) {
            return [];
        }
        krsort($levels);

        return reset($levels);
    }

    private function targets(TierPriceRuleData $rule, TierPriceContext $context): bool
    {
        if (null !== $rule->variantId && $rule->variantId !== $context->variantId) {
            return false;
        }
        if (null !== $rule->customerGroupId && $rule->customerGroupId !== $context->customerGroupId) {
            return false;
        }

        return $rule->isActiveAt($context->now);
    }

    private function applies(TierPriceRuleData $rule, TierPriceContext $context): bool
    {
        if ($context->promoted && TierPriceRuleInterface::CUMULATION_SKIP_IF_PROMOTED === $rule->cumulation) {
            return false;
        }

        return $context->quantityFor($rule) >= $rule->minQuantity;
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
