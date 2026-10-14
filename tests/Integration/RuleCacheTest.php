<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusTierPricingPlugin\TierPricingTestTrait;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleProviderInterface;

final class RuleCacheTest extends KernelTestCase
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

    public function testSavingOrDeletingARuleInvalidatesTheRulesOfItsProduct(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        $other = $this->catalog()->product($channel);
        $first = $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500);

        self::assertCount(1, $this->provider()->getRules($channel, $product, null));
        self::assertCount(1, $this->provider()->getRules($channel, $product, null), 'cached');

        $this->catalog()->rule($channel, $this->catalog()->variant($product, 1), 20, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 700);
        self::assertCount(2, $this->provider()->getRules($channel, $product, null));

        $first->setValue(900);
        $this->entityManager()->flush();
        $values = array_map(static fn (\Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleData $rule): int => $rule->value, $this->provider()->getRules($channel, $product, null));
        self::assertContains(900, $values);

        // Moved to another product: both products are invalidated.
        self::assertCount(0, $this->provider()->getRules($channel, $other, null));
        $first->setProduct($other);
        $this->entityManager()->flush();
        self::assertCount(1, $this->provider()->getRules($channel, $product, null));
        self::assertCount(1, $this->provider()->getRules($channel, $other, null));

        $this->entityManager()->remove($first);
        $this->entityManager()->flush();
        self::assertCount(0, $this->provider()->getRules($channel, $other, null));

        // Nothing is kept between requests of a long-running process either.
        $this->provider()->getRules($channel, $product, null);
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(\Symfony\Contracts\Service\ResetInterface::class, $resetter);
        $resetter->reset();
        self::assertCount(1, $this->provider()->getRules($channel, $product, null));
    }

    public function testGroupRulesAreOnlyReturnedForTheGroup(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $group = $this->catalog()->customerGroup();
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 900, $group);

        self::assertCount(1, $this->provider()->getRules($channel, $product, null));
        self::assertCount(2, $this->provider()->getRules($channel, $product, $group));
        self::assertCount(1, $this->provider()->getRules($channel, $product, $this->catalog()->customerGroup()));
    }

    private function provider(): TierPriceRuleProviderInterface
    {
        /** @var TierPriceRuleProviderInterface $provider */
        $provider = self::getContainer()->get('tmonier_tier_pricing.rule_provider');

        return $provider;
    }
}
