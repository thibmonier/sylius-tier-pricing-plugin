<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Integration;

use Sylius\Component\Core\Model\CatalogPromotionInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusTierPricingPlugin\TierPricingTestTrait;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleProviderInterface;

/** 1.1: taxon rules (any taxon of the product, children included), precedence, taxon basis, cumulation. */
final class TaxonRuleTest extends KernelTestCase
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

    /** Through any of its taxons (not only the main one), in a child of the taxon of the rule. */
    public function testATaxonRuleAppliesToTheProductsOfTheTaxonAndOfItsChildren(): void
    {
        $channel = $this->catalog()->channel();
        $tools = $this->catalog()->taxon();
        $drills = $this->catalog()->taxon($tools);
        $brand = $this->catalog()->taxon();
        $drill = $this->catalog()->product($channel, [1000]);
        $outside = $this->catalog()->product($channel, [1000]);
        // Main taxon "brand", also classified in "drills" (child of "tools").
        $this->catalog()->classify($drill, true, $brand, $drills);
        $this->catalog()->classify($outside, true, $brand);
        $this->catalog()->rule($channel, $tools, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);

        self::assertSame(900, $this->unitPrice($channel, $drill, 10));
        self::assertSame(1000, $this->unitPrice($channel, $drill, 9));
        self::assertSame(1000, $this->unitPrice($channel, $outside, 10));
    }

    /** Variant and product rules win over taxon rules, the deepest taxon over its parents, even when they give a higher price. */
    public function testPrecedenceProductThenTheDeepestTaxon(): void
    {
        $channel = $this->catalog()->channel();
        $tools = $this->catalog()->taxon();
        $drills = $this->catalog()->taxon($tools);
        $withProductRule = $this->catalog()->product($channel, [1000]);
        $withTaxonRules = $this->catalog()->product($channel, [1000]);
        $this->catalog()->classify($withProductRule, true, $drills);
        $this->catalog()->classify($withTaxonRules, true, $drills);
        $this->catalog()->rule($channel, $tools, 5, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 3000);
        $this->catalog()->rule($channel, $drills, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $withProductRule, 20, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500);

        // Product rules exist: below their threshold, no taxon rule applies.
        self::assertSame(1000, $this->unitPrice($channel, $withProductRule, 15));
        self::assertSame(950, $this->unitPrice($channel, $withProductRule, 20));
        // Deepest taxon (drills) first: the -30 % of "tools" never applies.
        self::assertSame(1000, $this->unitPrice($channel, $withTaxonRules, 5));
        self::assertSame(900, $this->unitPrice($channel, $withTaxonRules, 10));
    }

    /** A rule of another customer group does not hide the taxon rules. */
    public function testALevelCountsOnlyWithRulesForTheCustomer(): void
    {
        $channel = $this->catalog()->channel();
        $tools = $this->catalog()->taxon();
        $product = $this->catalog()->product($channel, [1000]);
        $this->catalog()->classify($product, true, $tools);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 2000, $this->catalog()->customerGroup());
        $this->catalog()->rule($channel, $tools, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);

        self::assertSame(900, $this->unitPrice($channel, $product, 10));
    }

    /** Taxon basis: every line of the taxon (and of its children) counts, mixed products. */
    public function testTheTaxonBasisCountsEveryProductOfTheTaxon(): void
    {
        $channel = $this->catalog()->channel();
        $tools = $this->catalog()->taxon();
        $drills = $this->catalog()->taxon($tools);
        $drill = $this->catalog()->product($channel, [1000]);
        $saw = $this->catalog()->product($channel, [2000]);
        $other = $this->catalog()->product($channel, [500]);
        $this->catalog()->classify($drill, true, $drills);
        $this->catalog()->classify($saw, false, $tools);
        $this->catalog()->rule($channel, $tools, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, basis: TierPriceRuleInterface::BASIS_TAXON);

        $order = $this->catalog()->cart($channel, [[$this->catalog()->variant($drill), 4], [$this->catalog()->variant($saw), 6], [$this->catalog()->variant($other), 20]], flush: false);

        self::assertSame(900, $this->catalog()->item($order, $this->catalog()->variant($drill))->getUnitPrice());
        self::assertSame(1800, $this->catalog()->item($order, $this->catalog()->variant($saw))->getUnitPrice());
        self::assertSame(500, $this->catalog()->item($order, $this->catalog()->variant($other))->getUnitPrice());
        $marker = $this->catalog()->item($order, $this->catalog()->variant($drill))->getAdjustments(TierPriceOrderProcessor::ADJUSTMENT_TYPE)->first();
        self::assertNotFalse($marker);
        self::assertTrue($marker->getDetails()['taxonBasis'] ?? null);

        // 9 of the taxon: no tier.
        $order = $this->catalog()->cart($channel, [[$this->catalog()->variant($drill), 4], [$this->catalog()->variant($saw), 5]], flush: false);
        self::assertSame(1000, $this->catalog()->item($order, $this->catalog()->variant($drill))->getUnitPrice());
    }

    public function testCumulationWithACatalogPromotion(): void
    {
        $channel = $this->catalog()->channel();
        $tools = $this->catalog()->taxon();
        $onTop = $this->promotedProduct($channel);
        $bestOf = $this->promotedProduct($channel);
        $skipped = $this->promotedProduct($channel);
        $this->catalog()->rule($channel, $onTop, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, cumulation: TierPriceRuleInterface::CUMULATION_ON_TOP);
        $this->catalog()->rule($channel, $bestOf, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $skipped, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 3000, cumulation: TierPriceRuleInterface::CUMULATION_SKIP_IF_PROMOTED);
        // The skipped product rule does not hand over to a taxon rule.
        $this->catalog()->classify($skipped, true, $tools);
        $this->catalog()->rule($channel, $tools, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 5000);

        // Promotion price 8.00 (regular 10.00).
        self::assertSame(720, $this->unitPrice($channel, $onTop, 10), 'on top: -10 % of 8.00');
        self::assertSame(800, $this->unitPrice($channel, $bestOf, 10), 'best of 9.00 and 8.00');
        self::assertSame(800, $this->unitPrice($channel, $skipped, 10), 'no tier for a promoted variant');
    }

    public function testSkipIfPromotedAppliesWithoutPromotion(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, cumulation: TierPriceRuleInterface::CUMULATION_SKIP_IF_PROMOTED);

        self::assertSame(900, $this->unitPrice($channel, $product, 10));
    }

    /** A taxon rule changes the rules of every product of the taxon: cached rules are forgotten. */
    public function testTheCacheIsInvalidatedByTaxonRulesAndClassifications(): void
    {
        $channel = $this->catalog()->channel();
        $tools = $this->catalog()->taxon();
        $product = $this->catalog()->product($channel, [1000]);
        $this->catalog()->classify($product, true, $tools);
        /** @var TierPriceRuleProviderInterface $provider */
        $provider = self::getContainer()->get('tmonier_tier_pricing.rule_provider');
        self::assertCount(0, $provider->getRules($channel, $product, null));

        $rule = $this->catalog()->rule($channel, $tools, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        self::assertCount(1, $provider->getRules($channel, $product, null));

        $other = $this->catalog()->product($channel, [1000]);
        self::assertCount(0, $provider->getRules($channel, $other, null));
        $this->catalog()->classify($other, false, $tools);
        self::assertCount(1, $provider->getRules($channel, $other, null));

        $this->entityManager()->remove($rule);
        $this->entityManager()->flush();
        self::assertCount(0, $provider->getRules($channel, $product, null));
    }

    private function unitPrice(\Sylius\Component\Core\Model\ChannelInterface $channel, \Sylius\Component\Core\Model\ProductInterface $product, int $quantity): int
    {
        $variant = $this->catalog()->variant($product);
        $order = $this->catalog()->cart($channel, [[$variant, $quantity]], flush: false);

        return $this->catalog()->item($order, $variant)->getUnitPrice();
    }

    private function promotedProduct(\Sylius\Component\Core\Model\ChannelInterface $channel): \Sylius\Component\Core\Model\ProductInterface
    {
        $product = $this->catalog()->product($channel, [1000]);
        $pricing = $this->catalog()->variant($product)->getChannelPricingForChannel($channel);
        self::assertNotNull($pricing);
        $pricing->setPrice(800);
        $pricing->setOriginalPrice(1000);
        /** @var \Sylius\Resource\Factory\FactoryInterface<CatalogPromotionInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.catalog_promotion');
        $promotion = $factory->createNew();
        $promotion->setCode('tmonier_cp_' . bin2hex(random_bytes(4)));
        $promotion->setName('Test catalog promotion');
        $this->entityManager()->persist($promotion);
        $pricing->addAppliedPromotion($promotion);
        $this->entityManager()->flush();

        return $product;
    }
}
