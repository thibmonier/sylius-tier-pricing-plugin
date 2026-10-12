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
     * @param array<int, int>|null $taxonQuantities quantity in the cart per taxon id (taxon rules counting the taxon); the product quantity when null
     */
    public function quote(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity, int $productQuantity, ?int $currentPrice = null, ?array $taxonQuantities = null): ?TierPriceQuote;

    /**
     * Every price break of the variant, and the next one from the quantities already in the
     * cart (`lineQuantity` of the variant, `productQuantity` of the whole product,
     * `taxonQuantities` per taxon id).
     *
     * @param array<int, int>|null $taxonQuantities
     */
    public function table(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity = 0, int $productQuantity = 0, ?array $taxonQuantities = null): TierPriceTable;
}
