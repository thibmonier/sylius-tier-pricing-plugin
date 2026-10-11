<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Factory;

use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

/**
 * @extends FactoryInterface<TierPriceRuleInterface>
 */
interface TierPriceRuleFactoryInterface extends FactoryInterface
{
    /** A product-wide rule of the product (identifier from the "Volume pricing" product tab), if it exists. */
    public function createForProduct(mixed $productId): TierPriceRuleInterface;
}
