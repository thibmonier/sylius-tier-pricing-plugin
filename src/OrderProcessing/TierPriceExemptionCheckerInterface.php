<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * Extension point: an exempt cart line keeps its price and does not count in the quantity of
 * its product. Services implementing this interface are tagged
 * `tmonier_tier_pricing.exemption_checker` automatically.
 */
interface TierPriceExemptionCheckerInterface
{
    public function isExempt(OrderItemInterface $item): bool;
}
