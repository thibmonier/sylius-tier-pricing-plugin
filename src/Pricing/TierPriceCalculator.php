<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final class TierPriceCalculator implements TierPriceCalculatorInterface
{
    public function __construct(
        private readonly TierPriceRuleProviderInterface $ruleProvider,
        private readonly TierPriceResolverInterface $resolver,
        private readonly ProductVariantPricesCalculatorInterface $pricesCalculator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function quote(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity, int $productQuantity, ?int $currentPrice = null): ?TierPriceQuote
    {
        $rules = $this->rules($variant, $channel, $customerGroup);
        if ([] === $rules) {
            return null;
        }

        return $this->quoteFor($rules, $this->context($variant, $customerGroup, $lineQuantity, $productQuantity), $variant, $channel, $currentPrice);
    }

    public function table(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity = 0, int $productQuantity = 0): TierPriceTable
    {
        $rules = $this->rules($variant, $channel, $customerGroup);
        $context = $this->context($variant, $customerGroup, 1, 1);
        $unitPrice = $this->catalogPrices($variant, $channel)['current'] ?? null;
        if ([] === $rules || null === $unitPrice) {
            return new TierPriceTable(null === $unitPrice ? [] : [new TierPriceRow(1, $unitPrice, 0.0, false)]);
        }

        $thresholds = [];
        foreach ($rules as $rule) {
            if ((null === $rule->variantId || $rule->variantId === $context->variantId) && $rule->isActiveAt($context->now)) {
                $thresholds[$rule->minQuantity] = true;
            }
        }
        ksort($thresholds);

        $rows = [new TierPriceRow(1, $unitPrice, 0.0, false)];
        $lastPrice = $unitPrice;
        foreach (array_keys($thresholds) as $threshold) {
            $quote = $this->quoteFor($rules, $this->context($variant, $customerGroup, $threshold, $threshold), $variant, $channel, null);
            if (null === $quote || $quote->unitPrice >= $lastPrice) {
                continue;
            }
            $rows[] = new TierPriceRow(
                $threshold,
                $quote->unitPrice,
                round(($unitPrice - $quote->unitPrice) * 100 / max(1, $unitPrice), 1),
                TierPriceRuleInterface::BASIS_PRODUCT === $quote->rule->quantityBasis,
            );
            $lastPrice = $quote->unitPrice;
        }

        if ($lineQuantity <= 0 && $productQuantity <= 0) {
            return new TierPriceTable($rows);
        }

        $current = $this->context($variant, $customerGroup, $lineQuantity, $productQuantity);
        $currentUnitPrice = $this->quoteFor($rules, $current, $variant, $channel, null)->unitPrice ?? $unitPrice;

        return new TierPriceTable($rows, $this->nudge($rules, $current, $variant, $channel, $currentUnitPrice), $currentUnitPrice);
    }

    /**
     * Smallest quantity to add (of this variant) for a lower unit price.
     *
     * @param list<TierPriceRuleData> $rules
     */
    private function nudge(array $rules, TierPriceContext $current, ProductVariantInterface $variant, ChannelInterface $channel, int $currentUnitPrice): ?TierPriceNudge
    {
        $best = null;
        foreach ($rules as $rule) {
            $quantity = TierPriceRuleInterface::BASIS_PRODUCT === $rule->quantityBasis ? $current->productQuantity : $current->lineQuantity;
            $toAdd = $rule->minQuantity - $quantity;
            if ($toAdd <= 0) {
                continue;
            }
            $quote = $this->quoteFor($rules, $current->withAddedQuantity($toAdd), $variant, $channel, null);
            if (null === $quote || $quote->unitPrice >= $currentUnitPrice) {
                continue;
            }
            if (null === $best || $toAdd < $best->quantityToAdd || ($toAdd === $best->quantityToAdd && $quote->unitPrice < $best->unitPrice)) {
                $best = new TierPriceNudge($toAdd, $quote->unitPrice, $quote->rule->minQuantity, TierPriceRuleInterface::BASIS_PRODUCT === $quote->rule->quantityBasis);
            }
        }

        return $best;
    }

    /** @param list<TierPriceRuleData> $rules */
    private function quoteFor(array $rules, TierPriceContext $context, ProductVariantInterface $variant, ChannelInterface $channel, ?int $currentPrice): ?TierPriceQuote
    {
        $rule = $this->resolver->resolve($rules, $context);
        if (null === $rule) {
            return null;
        }
        $prices = $this->catalogPrices($variant, $channel);
        if (null === $prices) {
            return null;
        }
        $currentPrice ??= $prices['current'];

        return new TierPriceQuote($rule, $this->resolver->calculateUnitPrice($rule, $prices['base'], $currentPrice, $prices['minimum']), $prices['base'], $currentPrice);
    }

    /** @return list<TierPriceRuleData> */
    private function rules(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup): array
    {
        $product = $variant->getProduct();
        if (!$product instanceof ProductInterface || null === $variant->getId()) {
            return [];
        }

        return $this->ruleProvider->getRules($channel, $product, $customerGroup);
    }

    private function context(ProductVariantInterface $variant, ?CustomerGroupInterface $customerGroup, int $lineQuantity, int $productQuantity): TierPriceContext
    {
        $variantId = $variant->getId();
        $groupId = $customerGroup?->getId();

        return new TierPriceContext(is_int($variantId) ? $variantId : 0, is_int($groupId) ? $groupId : null, $lineQuantity, $productQuantity, $this->clock->now());
    }

    /**
     * Regular price (before catalog promotions: the base of a percentage), current price and
     * floor of the variant in the channel.
     *
     * @return array{base: int, current: int, minimum: int}|null
     */
    private function catalogPrices(ProductVariantInterface $variant, ChannelInterface $channel): ?array
    {
        $channelPricing = $variant->getChannelPricingForChannel($channel);
        if (null === $channelPricing) {
            return null;
        }

        try {
            $current = $this->pricesCalculator->calculate($variant, ['channel' => $channel]);
            // Catalog promotions keep the regular price in originalPrice.
            $base = $channelPricing->getAppliedPromotions()->isEmpty() ? $current : $this->pricesCalculator->calculateOriginal($variant, ['channel' => $channel]);
        } catch (MissingChannelConfigurationException) {
            return null;
        }

        return ['base' => max($base, $current), 'current' => $current, 'minimum' => $channelPricing->getMinimumPrice()];
    }
}
