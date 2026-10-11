<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Integration;

use Sylius\Component\Order\Processor\CompositeOrderProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;

/**
 * Cohabitation with tmonier/sylius-quote-request-plugin without installing it: its
 * QuotePriceLockProcessor is registered with priority 45 (and its locked lines are immutable,
 * see OrderProcessingTest::testImmutableItemsAreLeftUntouchedAndDoNotCount). The tier processor
 * must run after it and after the prices recalculator, before shipping, promotions and taxes.
 */
final class ProcessorPriorityTest extends KernelTestCase
{
    private const QUOTE_PRICE_LOCK_PRIORITY = 45;

    public function testTheTierProcessorRunsAfterThePriceLockAndBeforePromotions(): void
    {
        self::bootKernel();
        $composite = self::getContainer()->get('sylius.order_processing.order_processor');
        self::assertInstanceOf(CompositeOrderProcessor::class, $composite);

        $queue = (new \ReflectionProperty(CompositeOrderProcessor::class, 'orderProcessors'))->getValue($composite);
        self::assertInstanceOf(\Laminas\Stdlib\PriorityQueue::class, $queue);
        $priorities = [];
        foreach ($queue->toArray(\Laminas\Stdlib\PriorityQueue::EXTR_BOTH) as $entry) {
            self::assertIsArray($entry);
            self::assertIsObject($entry['data']);
            $priorities[$entry['data']::class][] = $entry['priority'];
        }

        $tier = $priorities[TierPriceOrderProcessor::class][0] ?? null;
        self::assertIsInt($tier);
        self::assertLessThanOrEqual(self::QUOTE_PRICE_LOCK_PRIORITY - 1, $tier);
        self::assertLessThan($priorities[\Sylius\Component\Core\OrderProcessing\OrderPricesRecalculator::class][0], $tier);
        self::assertGreaterThan($priorities[\Sylius\Component\Core\OrderProcessing\OrderShipmentProcessor::class][0], $tier);
        self::assertGreaterThan($priorities[\Sylius\Component\Core\OrderProcessing\OrderPromotionProcessor::class][0], $tier);
        self::assertGreaterThan($priorities[\Sylius\Component\Core\OrderProcessing\OrderTaxesProcessor::class][0], $tier);
    }
}
