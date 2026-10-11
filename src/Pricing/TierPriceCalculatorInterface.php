<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;

interface TierPriceCalculatorInterface
{
    /**
     * The tier price of the variant for the quantities, or null when no rule applies (or the
     * variant has no price in the channel).
     *
     * @param int|null $currentPrice price without tier; the catalog price of the channel when null
     */
    public function quote(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity, int $productQuantity, ?int $currentPrice = null): ?TierPriceQuote;

    /**
     * Every price break of the variant, and the next one from the quantities already in the
     * cart (`lineQuantity` of the variant, `productQuantity` of the whole product).
     */
    public function table(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity = 0, int $productQuantity = 0): TierPriceTable;
}
