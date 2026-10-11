<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\OrderProcessing;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Order\Factory\AdjustmentFactory;
use Symfony\Component\Translation\IdentityTranslator;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceExemptionCheckerInterface;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface;

final class TierPriceOrderProcessorTest extends TestCase
{
    public function testTheQuantitiesOfTheLineAndOfTheProductArePassed(): void
    {
        $calculator = $this->calculator(10, 800);
        $product = $this->product(1);
        $order = $this->order([$this->item($product, 2, 6, 1000), $this->item($product, 3, 4, 1000)]);

        $this->processor($calculator)->process($order);

        self::assertSame([[6, 10, 1000], [4, 10, 1000]], $calculator->calls);
        foreach ($order->getItems() as $item) {
            self::assertSame(800, $item->getUnitPrice());
            self::assertCount(1, $item->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE));
        }
    }

    public function testAnOrderThatCannotBeProcessedIsLeftAlone(): void
    {
        $calculator = $this->calculator(1, 800);
        $order = $this->order([$this->item($this->product(1), 2, 10, 1000)]);
        $order->setState(OrderInterface::STATE_NEW);

        $this->processor($calculator)->process($order);

        self::assertSame([], $calculator->calls);
        self::assertSame(1000, $order->getItems()->first() instanceof OrderItemInterface ? $order->getItems()->first()->getUnitPrice() : null);
    }

    public function testExemptAndImmutableItemsAreSkippedAndNotCounted(): void
    {
        $calculator = $this->calculator(1, 800);
        $product = $this->product(1);
        $exempt = $this->item($product, 2, 5, 1000);
        $immutable = $this->item($product, 3, 5, 1000);
        $immutable->setImmutable(true);
        $normal = $this->item($product, 4, 2, 1000);
        $order = $this->order([$exempt, $immutable, $normal]);
        $checker = new class($exempt) implements TierPriceExemptionCheckerInterface {
            public function __construct(private readonly OrderItemInterface $exempt)
            {
            }

            public function isExempt(OrderItemInterface $item): bool
            {
                return $item === $this->exempt;
            }
        };

        $this->processor($calculator, $checker)->process($order);

        self::assertSame([[2, 2, 1000]], $calculator->calls);
        self::assertSame(1000, $exempt->getUnitPrice());
        self::assertSame(1000, $immutable->getUnitPrice());
        self::assertSame(800, $normal->getUnitPrice());
    }

    public function testAStaleMarkerIsRemovedWhenNoTierAppliesAnyMore(): void
    {
        $item = $this->item($this->product(1), 2, 1, 1000);
        $marker = new Adjustment();
        $marker->setType(TierPriceOrderProcessor::ADJUSTMENT_TYPE);
        $marker->setNeutral(true);
        $item->addAdjustment($marker);
        $order = $this->order([$item]);

        $this->processor($this->calculator(10, 800))->process($order);

        self::assertCount(0, $item->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE));
        self::assertNull(TierPriceOrderProcessor::priceWithoutTier($item));
    }

    private function processor(TierPriceCalculatorInterface $calculator, ?TierPriceExemptionCheckerInterface $checker = null): TierPriceOrderProcessor
    {
        $checker ??= new class() implements TierPriceExemptionCheckerInterface {
            public function isExempt(OrderItemInterface $item): bool
            {
                return false;
            }
        };

        return new TierPriceOrderProcessor($calculator, $checker, new AdjustmentFactory(new AdjustmentTestFactory()), new IdentityTranslator());
    }

    private function calculator(int $minQuantity, int $price): RecordingTierPriceCalculator
    {
        return new RecordingTierPriceCalculator($minQuantity, $price);
    }

    /** @param list<OrderItemInterface> $items */
    private function order(array $items): Order
    {
        $order = new Order();
        $order->setChannel(new Channel());
        $order->setLocaleCode('en_US');
        foreach ($items as $item) {
            $order->addItem($item);
        }

        return $order;
    }

    private function product(int $id): Product
    {
        $product = new Product();
        (new \ReflectionProperty(Product::class, 'id'))->setValue($product, $id);

        return $product;
    }

    private function item(Product $product, int $variantId, int $quantity, int $unitPrice): OrderItem
    {
        $variant = new ProductVariant();
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, $variantId);
        $product->addVariant($variant);
        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setUnitPrice($unitPrice);
        for ($i = 0; $i < $quantity; ++$i) {
            new OrderItemUnit($item);
        }

        return $item;
    }
}
