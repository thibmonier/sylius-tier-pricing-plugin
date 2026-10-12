<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Twig;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceExemptionCheckerInterface;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;
use Tmonier\SyliusTierPricingPlugin\Pricing\OrderCustomerGroupResolver;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolverInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TaxonQuantityCounter;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceTable;
use Tmonier\SyliusTierPricingPlugin\Repository\TierPriceRuleRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TierPricingExtension extends AbstractExtension
{
    public function __construct(
        private readonly TierPriceCalculatorInterface $calculator,
        private readonly PriceDisplayResolverInterface $displayResolver,
        private readonly ChannelContextInterface $channelContext,
        private readonly CustomerContextInterface $customerContext,
        private readonly CartContextInterface $cartContext,
        private readonly TierPriceRuleRepositoryInterface $ruleRepository,
        private readonly TierPriceExemptionCheckerInterface $exemptionChecker,
        private readonly OrderCustomerGroupResolver $customerGroupResolver,
        private readonly bool $productPage,
        private readonly bool $cart,
        private readonly TaxonQuantityCounter $taxonQuantityCounter = new TaxonQuantityCounter(),
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_tier_pricing_table', $this->table(...)),
            new TwigFunction('tmonier_tier_pricing_cart_table', $this->cartTable(...)),
            new TwigFunction('tmonier_tier_pricing_tax_convention', $this->taxConvention(...)),
            new TwigFunction('tmonier_tier_pricing_is_b2b', $this->isBusinessChannel(...)),
            new TwigFunction('tmonier_tier_pricing_shows_percentage', $this->showsPercentage(...)),
            new TwigFunction('tmonier_tier_pricing_item_tier', $this->itemTier(...)),
            new TwigFunction('tmonier_tier_pricing_rules_of_product', $this->rulesOfProduct(...)),
        ];
    }

    /**
     * Price breaks of the variant (the one selected in the add to cart form) for the current
     * customer and channel, with the next tier from the quantities already in the cart.
     */
    public function table(?ProductVariantInterface $variant): ?TierPriceTable
    {
        if (!$this->productPage) {
            return null;
        }
        $channel = $this->channel();
        if (null === $variant || null === $channel) {
            return null;
        }

        [$lineQuantity, $productQuantity, $taxonQuantities] = $this->cartQuantities($variant);
        $table = $this->calculator->table($variant, $channel, $this->customerContext->getCustomer()?->getGroup(), $lineQuantity, $productQuantity, $taxonQuantities);

        return $table->hasTiers() ? $table : null;
    }

    /** Price breaks and next tier of a cart line. */
    public function cartTable(OrderItemInterface $item): ?TierPriceTable
    {
        $order = $item->getOrder();
        $variant = $item->getVariant();
        // Same lines as the order processor: immutable and exempt lines get no tier price.
        if (!$this->cart || !$order instanceof OrderInterface || null === $variant || $item->isImmutable() || $this->exemptionChecker->isExempt($item)) {
            return null;
        }
        $channel = $order->getChannel();
        if (null === $channel) {
            return null;
        }

        [$lineQuantity, $productQuantity, $taxonQuantities] = $this->orderQuantities($order, $variant);
        $table = $this->calculator->table($variant, $channel, $this->customerGroupResolver->resolve($order), $lineQuantity, $productQuantity, $taxonQuantities);

        return $table->hasTiers() ? $table : null;
    }

    /** `included`, `excluded` or null (unknown: no label), for the variant in the current channel. */
    public function taxConvention(ProductVariantInterface $variant): ?string
    {
        $channel = $this->channel();

        return null === $channel ? null : $this->displayResolver->taxConvention($variant, $channel);
    }

    public function isBusinessChannel(): bool
    {
        $channel = $this->channel();

        return null !== $channel && $this->displayResolver->isBusinessChannel($channel);
    }

    public function showsPercentage(): bool
    {
        $channel = $this->channel();

        return null !== $channel && $this->displayResolver->showsPercentage($channel);
    }

    /**
     * The tier applied to the cart line (details of its `tmonier_tier_price` adjustment).
     *
     * @return array<string, mixed>|null
     */
    public function itemTier(OrderItemInterface $item): ?array
    {
        $marker = $item->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE)->first();

        if (!$marker instanceof \Sylius\Component\Order\Model\AdjustmentInterface) {
            return null;
        }
        $details = [];
        foreach ($marker->getDetails() as $key => $value) {
            $details[(string) $key] = $value;
        }

        return $details;
    }

    /** @return list<\Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface> */
    public function rulesOfProduct(ProductInterface $product): array
    {
        return null === $product->getId() ? [] : $this->ruleRepository->findByProduct($product);
    }

    /** @return array{int, int, array<int, int>|null} */
    private function cartQuantities(ProductVariantInterface $variant): array
    {
        try {
            $cart = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return [0, 0, null];
        }

        return $cart instanceof OrderInterface ? $this->orderQuantities($cart, $variant) : [0, 0, null];
    }

    /** @return array{int, int, array<int, int>} quantity of the variant, of the product, per taxon (mutable, non-exempt lines only, as the order processor counts) */
    private function orderQuantities(OrderInterface $order, ProductVariantInterface $variant): array
    {
        $lineQuantity = 0;
        $productQuantity = 0;
        $items = [];
        foreach ($order->getItems() as $item) {
            if ($item->isImmutable() || $this->exemptionChecker->isExempt($item)) {
                continue;
            }
            $items[] = $item;
            if ($item->getVariant() === $variant) {
                $lineQuantity += $item->getQuantity();
            }
            if (null !== $variant->getProduct() && $item->getVariant()?->getProduct() === $variant->getProduct()) {
                $productQuantity += $item->getQuantity();
            }
        }

        return [$lineQuantity, $productQuantity, $this->taxonQuantityCounter->count($items)];
    }

    private function channel(): ?ChannelInterface
    {
        try {
            $channel = $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            return null;
        }

        return $channel instanceof ChannelInterface ? $channel : null;
    }
}
