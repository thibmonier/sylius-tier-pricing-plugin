<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\OrderProcessing;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\OrderCustomerGroupResolver;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolverInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TaxonQuantityCounter;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface;

/**
 * Registered with priority 44: after the prices recalculator (50) and the quote price lock of
 * tmonier/sylius-quote-request-plugin (45), before shipping (40), cart promotions (20) and taxes
 * (10). Cart promotions and free shipping thresholds therefore see the tier prices.
 *
 * Immutable items (e.g. locked quote lines) are left untouched. The channel pricings are never
 * written (the price history used for the Omnibus lowest price stays the catalog one): only the
 * unit price of the cart line changes (and, in consumer channels, its original unit price, so that
 * no reduction from the regular price is implied), and a neutral `tmonier_tier_price` adjustment
 * (no effect on totals) records the rule applied with the price without tier.
 */
final class TierPriceOrderProcessor implements OrderProcessorInterface
{
    public const ADJUSTMENT_TYPE = 'tmonier_tier_price';

    /** @param AdjustmentFactoryInterface<\Sylius\Component\Order\Model\AdjustmentInterface> $adjustmentFactory */
    public function __construct(
        private readonly TierPriceCalculatorInterface $calculator,
        private readonly TierPriceExemptionCheckerInterface $exemptionChecker,
        private readonly AdjustmentFactoryInterface $adjustmentFactory,
        private readonly TranslatorInterface $translator,
        private readonly OrderCustomerGroupResolver $customerGroupResolver = new OrderCustomerGroupResolver(),
        private readonly ?PriceDisplayResolverInterface $displayResolver = null,
        private readonly TaxonQuantityCounter $taxonQuantityCounter = new TaxonQuantityCounter(),
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        if (!$order instanceof OrderInterface || !$order->canBeProcessed()) {
            return;
        }
        $channel = $order->getChannel();
        if (null === $channel) {
            return;
        }

        /** @var list<OrderItemInterface> $items */
        $items = [];
        $productQuantities = [];
        foreach ($order->getItems() as $item) {
            if ($item->isImmutable()) {
                continue;
            }
            // The prices recalculator already reset the unit price: the previous marker is stale.
            $item->removeAdjustments(self::ADJUSTMENT_TYPE);
            if ($this->exemptionChecker->isExempt($item)) {
                continue;
            }
            $productId = $item->getVariant()?->getProduct()?->getId();
            if (!is_int($productId)) {
                continue;
            }
            $items[] = $item;
            $productQuantities[$productId] = ($productQuantities[$productId] ?? 0) + $item->getQuantity();
        }

        // Taxon rules counting the taxon: every eligible line of the taxon and of its children.
        $taxonQuantities = $this->taxonQuantityCounter->count($items);
        $customerGroup = $this->customerGroupResolver->resolve($order);
        // Consumer channels: the regular price is not exposed as the original price of a tier
        // line (the shop API and headless fronts strike originalUnitPrice > unitPrice through).
        $hideRegularPrice = !($this->displayResolver?->isBusinessChannel($channel) ?? false);
        foreach ($items as $item) {
            $variant = $item->getVariant();
            $productId = $variant?->getProduct()?->getId();
            if (null === $variant || !is_int($productId)) {
                continue;
            }
            $currentPrice = $item->getUnitPrice();
            $quote = $this->calculator->quote($variant, $channel, $customerGroup, $item->getQuantity(), $productQuantities[$productId] ?? 0, $currentPrice, $taxonQuantities);
            if (null === $quote || !$quote->isCheaper()) {
                continue;
            }

            $item->setUnitPrice($quote->unitPrice);
            if ($hideRegularPrice) {
                $item->setOriginalUnitPrice($quote->unitPrice);
            }
            $item->addAdjustment($this->adjustmentFactory->createWithData(
                self::ADJUSTMENT_TYPE,
                $this->translator->trans('tmonier_tier_pricing.ui.adjustment_label', ['%quantity%' => $quote->rule->minQuantity], 'messages', $order->getLocaleCode()),
                ($quote->unitPrice - $currentPrice) * $item->getQuantity(),
                true,
                [
                    'ruleId' => $quote->rule->id,
                    'minQuantity' => $quote->rule->minQuantity,
                    'quantityBasis' => $quote->rule->quantityBasis,
                    'type' => $quote->rule->type,
                    'value' => $quote->rule->value,
                    'priceWithoutTier' => $currentPrice,
                    'mixAndMatch' => TierPriceRuleInterface::BASIS_PRODUCT === $quote->rule->quantityBasis,
                    'taxonBasis' => TierPriceRuleInterface::BASIS_TAXON === $quote->rule->quantityBasis,
                    'taxonId' => $quote->rule->taxonId,
                    'cumulation' => $quote->rule->cumulation,
                ],
            ));
        }
    }

    public static function hasTierPrice(OrderItemInterface $item): bool
    {
        return !$item->getAdjustments(self::ADJUSTMENT_TYPE)->isEmpty();
    }

    /** Unit price of the line before the tier price, when one applies. */
    public static function priceWithoutTier(OrderItemInterface $item): ?int
    {
        $marker = $item->getAdjustments(self::ADJUSTMENT_TYPE)->first();
        if (!$marker instanceof AdjustmentInterface) {
            return null;
        }
        $price = $marker->getDetails()['priceWithoutTier'] ?? null;

        return is_int($price) ? $price : null;
    }
}
