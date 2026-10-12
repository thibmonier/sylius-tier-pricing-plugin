<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Exemption;

use Sylius\Component\Core\Model\OrderItemInterface;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceExemptionCheckerInterface;

/** Test application: lines of variants whose code ends with `_exempt` get no tier price. */
final class ExemptVariantChecker implements TierPriceExemptionCheckerInterface
{
    public function isExempt(OrderItemInterface $item): bool
    {
        return str_ends_with((string) $item->getVariant()?->getCode(), '_exempt');
    }
}
