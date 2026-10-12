<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Integration;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\CatalogPromotionInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusTierPricingPlugin\TierPricingTestTrait;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;

final class OrderProcessingTest extends KernelTestCase
{
    use TierPricingTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testTheHighestThresholdReachedGivesTheUnitPrice(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $this->catalog()->rule($channel, $product, 5, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);

        $cases = [4 => 1000, 5 => 950, 9 => 950, 10 => 900, 100 => 900];
        foreach ($cases as $quantity => $expected) {
            $order = $this->catalog()->cart($channel, [[$variant, $quantity]], flush: false);
            $item = $this->catalog()->item($order, $variant);
            self::assertSame($expected, $item->getUnitPrice(), sprintf('quantity %d', $quantity));
            self::assertSame($expected * $quantity, $item->getSubtotal());
        }
    }

    public function testTheNeutralMarkerRecordsTheRuleAndDoesNotChangeTotals(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $rule = $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 800);

        $order = $this->catalog()->cart($channel, [[$variant, 10]]);
        $item = $this->catalog()->item($order, $variant);

        $markers = $item->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE);
        self::assertCount(1, $markers);
        $marker = $markers->first();
        self::assertInstanceOf(AdjustmentInterface::class, $marker);
        self::assertTrue($marker->isNeutral());
        self::assertSame(-2000, $marker->getAmount());
        self::assertSame($rule->getId(), $marker->getDetails()['ruleId']);
        self::assertSame(1000, $marker->getDetails()['priceWithoutTier']);
        self::assertSame(8000, $item->getTotal() - $item->getTaxTotal());
        self::assertSame(8000, $order->getItemsTotal());

        // Back under the threshold: price and marker go away, no stale marker is left.
        $this->catalog()->changeQuantity($order, $item, 1);
        self::assertSame(1000, $item->getUnitPrice());
        self::assertCount(0, $item->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE));
    }

    public function testTheBestApplicablePriceWinsWhateverTheSpecificity(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        [$first, $second] = [$this->catalog()->variant($product, 0), $this->catalog()->variant($product, 1)];
        $group = $this->catalog()->customerGroup();
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 2000);
        $this->catalog()->rule($channel, $first, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 950);
        $this->catalog()->rule($channel, $second, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 750);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 3000, $group);

        $order = $this->catalog()->cart($channel, [[$first, 10], [$second, 10]], flush: false);
        // A more specific but more expensive rule never wins over a cheaper applicable one.
        self::assertSame(800, $this->catalog()->item($order, $first)->getUnitPrice());
        self::assertSame(750, $this->catalog()->item($order, $second)->getUnitPrice());

        $order = $this->catalog()->cart($channel, [[$first, 10], [$second, 10]], $this->customerOfGroup($group), flush: false);
        self::assertSame(700, $this->catalog()->item($order, $first)->getUnitPrice());
        self::assertSame(700, $this->catalog()->item($order, $second)->getUnitPrice());
    }

    public function testThePriceNeverGoesUpWithTheQuantityAndTheTableShowsWhatTheCartCharges(): void
    {
        // Regression: everybody 10+ at 8.00, group 20+ at 9.50. A group customer buying 20
        // used to pay 9.50 (more than 19 pieces) while the table showed 8.00 from 10.
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $group = $this->catalog()->customerGroup();
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 800);
        $this->catalog()->rule($channel, $product, 20, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 950, $group);
        $this->catalog()->rule($channel, $variant, 30, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 990);
        $this->catalog()->rule($channel, $product, 40, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 700, $group);
        $customer = $this->customerOfGroup($group);

        /** @var \Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface $calculator */
        $calculator = self::getContainer()->get('tmonier_tier_pricing.calculator');
        $table = $calculator->table($variant, $channel, $group);
        $rows = array_map(static fn ($row): array => [$row->minQuantity, $row->unitPrice], $table->rows);
        self::assertSame([[1, 1000], [10, 800], [40, 700]], $rows);

        $previous = \PHP_INT_MAX;
        foreach ([1, 9, 10, 19, 20, 29, 30, 39, 40, 80] as $quantity) {
            $price = $this->catalog()->item($this->catalog()->cart($channel, [[$variant, $quantity]], $customer, flush: false), $variant)->getUnitPrice();
            self::assertLessThanOrEqual($previous, $price, sprintf('quantity %d', $quantity));
            $expected = 1000;
            foreach ($rows as [$minQuantity, $rowPrice]) {
                if ($quantity >= $minQuantity) {
                    $expected = $rowPrice;
                }
            }
            self::assertSame($expected, $price, sprintf('the table row for %d pieces is the price charged', $quantity));
            $previous = $price;
        }
    }

    public function testGroupPricesNeedASignedInCustomer(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $group = $this->catalog()->customerGroup();
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 3000, $group);
        $customer = $this->customerOfGroup($group);

        // Guest checkout with the e-mail of an existing group customer: Sylius attaches the
        // customer to the order, but the order stays "created by guest".
        $order = $this->catalog()->cart($channel, [[$variant, 10]], flush: false);
        $order->setCustomer($customer);
        self::assertTrue($order->isCreatedByGuest());
        $this->catalog()->process($order);
        self::assertSame(900, $this->catalog()->item($order, $variant)->getUnitPrice());

        $order = $this->catalog()->cart($channel, [[$variant, 10]], $customer, flush: false);
        self::assertFalse($order->isCreatedByGuest());
        self::assertSame(700, $this->catalog()->item($order, $variant)->getUnitPrice());
    }

    public function testTheRegularPriceIsNotExposedAsTheOriginalPriceOfATierLine(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        [$first, $second] = [$this->catalog()->variant($product, 0), $this->catalog()->variant($product, 1)];
        $this->catalog()->rule($channel, $first, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 900);

        $order = $this->catalog()->cart($channel, [[$first, 10], [$second, 10]], flush: false);

        $tier = $this->catalog()->item($order, $first);
        self::assertSame(900, $tier->getUnitPrice());
        self::assertSame(900, $tier->getOriginalUnitPrice(), 'consumer channel: no reduction implied');
        self::assertSame(1000, TierPriceOrderProcessor::priceWithoutTier($tier));
        self::assertSame(1000, $this->catalog()->item($order, $second)->getOriginalUnitPrice());

        // Back under the threshold: the recalculator gives the regular original price back.
        $this->catalog()->changeQuantity($order, $tier, 2);
        self::assertSame(1000, $tier->getOriginalUnitPrice());
    }

    public function testMixAndMatchCountsEveryVariantOfTheProduct(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 2000]);
        [$first, $second] = [$this->catalog()->variant($product, 0), $this->catalog()->variant($product, 1)];
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, null, TierPriceRuleInterface::BASIS_PRODUCT);

        $order = $this->catalog()->cart($channel, [[$first, 6], [$second, 4]], flush: false);
        self::assertSame(900, $this->catalog()->item($order, $first)->getUnitPrice());
        self::assertSame(1800, $this->catalog()->item($order, $second)->getUnitPrice());

        // Per line: neither line reaches 10.
        $lineProduct = $this->catalog()->product($channel, [1000, 2000]);
        [$a, $b] = [$this->catalog()->variant($lineProduct, 0), $this->catalog()->variant($lineProduct, 1)];
        $this->catalog()->rule($channel, $lineProduct, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $order = $this->catalog()->cart($channel, [[$a, 6], [$b, 4]], flush: false);
        self::assertSame(1000, $this->catalog()->item($order, $a)->getUnitPrice());
        self::assertSame(2000, $this->catalog()->item($order, $b)->getUnitPrice());
    }

    public function testDisabledExpiredAndOtherChannelRulesAreIgnored(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $this->catalog()->rule($channel, $product, 2, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 100, enabled: false);
        $this->catalog()->rule($channel, $product, 3, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 200, endsAt: new \DateTime('-1 day'));
        $this->catalog()->rule($channel, $product, 4, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 300, startsAt: new \DateTime('+1 day'));
        $this->catalog()->rule($channel, $product, 5, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 900, startsAt: new \DateTime('-1 day'), endsAt: new \DateTime('+1 day'));

        $order = $this->catalog()->cart($channel, [[$variant, 5]], flush: false);
        self::assertSame(900, $this->catalog()->item($order, $variant)->getUnitPrice());
    }

    public function testTheBestOfTierAndCatalogPromotionApplies(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        // A catalog promotion lowered the price to 9.00 (regular price kept in originalPrice).
        $pricing = $variant->getChannelPricingForChannel($channel);
        self::assertNotNull($pricing);
        $pricing->setPrice(900);
        $pricing->setOriginalPrice(1000);
        $pricing->addAppliedPromotion($this->catalogPromotion());
        $this->entityManager()->flush();
        $this->catalog()->rule($channel, $product, 5, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 2000);

        // 5+: -5 % of the regular price = 9.50 > 9.00: the promotion price stays, no tier marker.
        $order = $this->catalog()->cart($channel, [[$variant, 5]], flush: false);
        $item = $this->catalog()->item($order, $variant);
        self::assertSame(900, $item->getUnitPrice());
        self::assertCount(0, $item->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE));

        // 10+: -20 % of the regular price = 8.00 < 9.00 (not cumulated: not 7.20).
        $order = $this->catalog()->cart($channel, [[$variant, 10]], flush: false);
        self::assertSame(800, $this->catalog()->item($order, $variant)->getUnitPrice());

        // Channel pricing untouched (price history / Omnibus lowest price).
        self::assertSame(900, $pricing->getPrice());
    }

    public function testTheMinimumPriceOfTheChannelPricingIsAFloor(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $pricing = $variant->getChannelPricingForChannel($channel);
        self::assertNotNull($pricing);
        $pricing->setMinimumPrice(850);
        $this->entityManager()->flush();
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 5000);

        $order = $this->catalog()->cart($channel, [[$variant, 10]], flush: false);
        self::assertSame(850, $this->catalog()->item($order, $variant)->getUnitPrice());
    }

    public function testImmutableItemsAreLeftUntouchedAndDoNotCount(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        [$first, $second] = [$this->catalog()->variant($product, 0), $this->catalog()->variant($product, 1)];
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, null, TierPriceRuleInterface::BASIS_PRODUCT);

        $order = $this->catalog()->cart($channel, [[$first, 8], [$second, 2]], flush: false);
        self::assertSame(900, $this->catalog()->item($order, $first)->getUnitPrice());

        // A locked line (e.g. a negotiated quote line of tmonier/sylius-quote-request-plugin).
        $locked = $this->catalog()->item($order, $second);
        $locked->setImmutable(true);
        $locked->setUnitPrice(555);
        $this->catalog()->process($order);

        self::assertSame(555, $locked->getUnitPrice());
        self::assertCount(1, $locked->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE), 'previous marker left as is');
        // 8 mutable units only: below the threshold.
        self::assertSame(1000, $this->catalog()->item($order, $first)->getUnitPrice());
    }

    public function testCartPromotionsSeeTheTierPrice(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 500);

        $order = $this->catalog()->cart($channel, [[$variant, 10]], flush: false);
        // Items total is computed on the tier price (documented: ItemTotal promotion and free
        // shipping thresholds see it).
        self::assertSame(5000, $order->getItemsSubtotal());
    }

    private function customerOfGroup(\Sylius\Component\Customer\Model\CustomerGroupInterface $group): CustomerInterface
    {
        /** @var \Sylius\Resource\Factory\FactoryInterface<CustomerInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.customer');
        $customer = $factory->createNew();
        $customer->setEmail(sprintf('tier-%s@example.com', bin2hex(random_bytes(4))));
        $customer->setGroup($group);
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        return $customer;
    }

    private function catalogPromotion(): CatalogPromotionInterface
    {
        /** @var \Sylius\Resource\Factory\FactoryInterface<CatalogPromotionInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.catalog_promotion');
        $promotion = $factory->createNew();
        $promotion->setCode('tmonier_cp_' . bin2hex(random_bytes(4)));
        $promotion->setName('Test catalog promotion');
        $this->entityManager()->persist($promotion);

        return $promotion;
    }
}
