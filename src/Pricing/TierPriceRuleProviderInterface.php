<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;

interface TierPriceRuleProviderInterface
{
    /** @return list<TierPriceRuleData> */
    public function getRules(ChannelInterface $channel, ProductInterface $product, ?CustomerGroupInterface $customerGroup): array;

    /** Forgets the rules of the products (after a rule change). */
    public function invalidateProducts(int ...$productIds): void;

    /** Forgets every cached rule (after a bulk change, e.g. a CSV import). */
    public function invalidateAll(): void;
}
