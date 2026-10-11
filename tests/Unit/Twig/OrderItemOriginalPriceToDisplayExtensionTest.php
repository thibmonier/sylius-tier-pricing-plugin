<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Sylius\Bundle\ShopBundle\Twig\OrderItemOriginalPriceToDisplayExtension as BaseExtension;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;
use Tmonier\SyliusTierPricingPlugin\Twig\OrderItemOriginalPriceToDisplayExtension;

final class OrderItemOriginalPriceToDisplayExtensionTest extends TestCase
{
    public function testWithoutTierTheCoreRuleApplies(): void
    {
        $item = $this->item(900, 1000);

        self::assertSame(1000, $this->extension()->getOriginalPriceToDisplay($item));
    }

    public function testATierPriceIsNotStruckThrough(): void
    {
        $item = $this->item(900, 1000);
        $this->markTier($item);

        self::assertNull($this->extension()->getOriginalPriceToDisplay($item));
    }

    public function testACartPromotionOnTopIsShownFromTheTierPrice(): void
    {
        $item = $this->item(900, 1000);
        $this->markTier($item);
        $promotion = new Adjustment();
        $promotion->setType(AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT);
        $promotion->setAmount(-100);
        $unit = $item->getUnits()->first();
        self::assertInstanceOf(OrderItemUnit::class, $unit);
        $unit->addAdjustment($promotion);

        self::assertSame(900, $this->extension()->getOriginalPriceToDisplay($item));
    }

    private function extension(): OrderItemOriginalPriceToDisplayExtension
    {
        return new OrderItemOriginalPriceToDisplayExtension(new BaseExtension());
    }

    private function item(int $unitPrice, int $originalUnitPrice): OrderItem
    {
        $item = new OrderItem();
        $item->setUnitPrice($unitPrice);
        $item->setOriginalUnitPrice($originalUnitPrice);
        new OrderItemUnit($item);

        return $item;
    }

    private function markTier(OrderItem $item): void
    {
        $marker = new Adjustment();
        $marker->setType(TierPriceOrderProcessor::ADJUSTMENT_TYPE);
        $marker->setAmount(-100);
        $marker->setNeutral(true);
        $item->addAdjustment($marker);
    }
}
