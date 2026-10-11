<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\OrderProcessing;

use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Order\Model\AdjustmentInterface;
use Sylius\Resource\Factory\FactoryInterface;

/** @implements FactoryInterface<AdjustmentInterface> */
final class AdjustmentTestFactory implements FactoryInterface
{
    public function createNew(): AdjustmentInterface
    {
        return new Adjustment();
    }
}
