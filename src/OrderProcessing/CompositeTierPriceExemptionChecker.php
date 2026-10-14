<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\OrderProcessing;

use Sylius\Component\Core\Model\OrderItemInterface;

final readonly class CompositeTierPriceExemptionChecker implements TierPriceExemptionCheckerInterface
{
    /** @param iterable<TierPriceExemptionCheckerInterface> $checkers */
    public function __construct(private iterable $checkers)
    {
    }

    public function isExempt(OrderItemInterface $item): bool
    {
        foreach ($this->checkers as $checker) {
            if ($checker->isExempt($item)) {
                return true;
            }
        }

        return false;
    }
}
