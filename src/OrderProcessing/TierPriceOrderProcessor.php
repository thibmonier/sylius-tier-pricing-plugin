<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\OrderProcessing;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface;

/**
 * Registered with priority 44: after the prices recalculator (50) and the quote price lock of
 * tmonier/sylius-quote-request-plugin (45), before shipping (40), cart promotions (20) and taxes
 * (10). Cart promotions and free shipping thresholds therefore see the tier prices.
 *
 * Immutable items (e.g. locked quote lines) are left untouched. The channel pricings are never
 * written (the price history used for the Omnibus lowest price stays the catalog one): only the
 * unit price of the cart line changes, and a neutral `tmonier_tier_price` adjustment (no effect
 * on totals) records the rule applied.
 */
final class TierPriceOrderProcessor implements OrderProcessorInterface
{
    public const ADJUSTMENT_TYPE = 'tmonier_tier_price';

    public function __construct(
        private readonly TierPriceCalculatorInterface $calculator,
        private readonly TierPriceExemptionCheckerInterface $exemptionChecker,
        private readonly AdjustmentFactoryInterface $adjustmentFactory,
        private readonly TranslatorInterface $translator,
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
            if (!$item instanceof OrderItemInterface || $item->isImmutable()) {
                continue;
            }
            // The prices recalculator already reset the unit price: the previous marker is stale.
            $item->removeAdjustments(self::ADJUSTMENT_TYPE);
            if ($this->exemptionChecker->isExempt($item)) {
                continue;
            }
            $productId = $item->getVariant()?->getProduct()?->getId();
            if (null === $productId) {
                continue;
            }
            $items[] = $item;
            $productQuantities[$productId] = ($productQuantities[$productId] ?? 0) + $item->getQuantity();
        }

        $customerGroup = $order->getCustomer()?->getGroup();
        foreach ($items as $item) {
            $variant = $item->getVariant();
            $product = $variant?->getProduct();
            if (null === $variant || !$product instanceof ProductInterface) {
                continue;
            }
            $currentPrice = $item->getUnitPrice();
            $quote = $this->calculator->quote($variant, $channel, $customerGroup, $item->getQuantity(), $productQuantities[$product->getId()] ?? 0, $currentPrice);
            if (null === $quote || !$quote->isCheaper()) {
                continue;
            }

            $item->setUnitPrice($quote->unitPrice);
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
