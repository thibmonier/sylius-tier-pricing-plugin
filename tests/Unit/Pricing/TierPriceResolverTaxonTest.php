<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceContext;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceResolver;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleData;

/** 1.1: precedence product > deepest taxon, taxon basis, cumulation modes. */
final class TierPriceResolverTaxonTest extends TestCase
{
    private const VARIANT = 7;

    private const GROUP = 3;

    public function testProductRulesHideTaxonRulesEvenBelowTheirThreshold(): void
    {
        $taxon = $this->percentage(1, 5, 3000, taxon: 10, level: 1);
        $product = $this->percentage(2, 20, 500);

        self::assertNull($this->resolve([$taxon, $product], $this->context(10)));
        self::assertSame(2, $this->resolve([$taxon, $product], $this->context(20))?->id);
        self::assertSame(1, $this->resolve([$taxon], $this->context(10))?->id);
    }

    public function testTheDeepestTaxonWinsAndRulesOfTheSameDepthCompete(): void
    {
        $root = $this->percentage(1, 2, 5000, taxon: 10, level: 0);
        $child = $this->percentage(2, 10, 1000, taxon: 11, level: 1);
        $sibling = $this->percentage(3, 10, 1500, taxon: 12, level: 1);

        self::assertNull($this->resolve([$root, $child], $this->context(5)));
        self::assertSame(2, $this->resolve([$root, $child], $this->context(10))?->id);
        self::assertSame(3, $this->resolve([$root, $child, $sibling], $this->context(10))?->id, 'best price within the level');
    }

    public function testRulesOfAnotherGroupVariantOrPeriodDoNotCountForTheLevel(): void
    {
        $taxon = $this->percentage(1, 5, 1000, taxon: 10, level: 0);

        self::assertSame(1, $this->resolve([$taxon, $this->percentage(2, 5, 2000, group: self::GROUP)], $this->context(5))?->id);
        self::assertSame(1, $this->resolve([$taxon, $this->percentage(3, 5, 2000, variant: 99)], $this->context(5))?->id);
        self::assertSame(1, $this->resolve([$taxon, $this->percentage(4, 5, 2000, endsAt: '2020-01-01')], $this->context(5))?->id);
        self::assertSame(5, $this->resolve([$taxon, $this->percentage(5, 5, 2000, group: self::GROUP)], $this->context(5, group: self::GROUP))?->id);
    }

    public function testTheTaxonBasisCountsTheQuantityOfTheTaxon(): void
    {
        $rule = $this->percentage(1, 10, 1000, taxon: 10, level: 0, basis: TierPriceRuleInterface::BASIS_TAXON);

        self::assertNull($this->resolve([$rule], $this->context(4, taxonQuantities: [10 => 9])));
        self::assertSame(1, $this->resolve([$rule], $this->context(4, taxonQuantities: [10 => 10, 11 => 1]))?->id);
        // Simulated quantity (product page): the product quantity.
        self::assertSame(1, $this->resolve([$rule], $this->context(10))?->id);
        self::assertSame(15, $this->context(4, taxonQuantities: [10 => 10])->withAddedQuantity(5)->quantityFor($rule));
    }

    public function testCumulationModes(): void
    {
        $resolver = new TierPriceResolver();
        // Regular 10.00, catalog promotion 8.00.
        self::assertSame(800, $resolver->calculateUnitPrice($this->percentage(1, 2, 1000), 1000, 800), 'best of 9.00 and 8.00');
        self::assertSame(720, $resolver->calculateUnitPrice($this->percentage(1, 2, 1000, cumulation: TierPriceRuleInterface::CUMULATION_ON_TOP), 1000, 800));
        self::assertSame(750, $resolver->calculateUnitPrice($this->percentage(1, 2, 1000, cumulation: TierPriceRuleInterface::CUMULATION_ON_TOP), 1000, 800, 750), 'floor');

        $skip = $this->percentage(1, 2, 1000, cumulation: TierPriceRuleInterface::CUMULATION_SKIP_IF_PROMOTED);
        $taxon = $this->percentage(2, 2, 5000, taxon: 10, level: 0);
        self::assertSame(1, $this->resolve([$skip, $taxon], $this->context(5))?->id);
        self::assertNull($this->resolve([$skip, $taxon], $this->context(5)->withPromoted(true)), 'no fallback to the taxon rule');
    }

    public function testThePriceNeverGoesUpWithTheQuantityAcrossLevels(): void
    {
        $rules = [
            $this->percentage(1, 5, 2000, taxon: 10, level: 0),
            $this->percentage(2, 10, 1000, taxon: 11, level: 1),
            $this->percentage(3, 3, 500, taxon: 11, level: 1),
        ];
        $resolver = new TierPriceResolver();
        $last = \PHP_INT_MAX;
        for ($quantity = 1; $quantity <= 30; ++$quantity) {
            $rule = $resolver->resolve($rules, $this->context($quantity), 1000, 1000);
            $price = null === $rule ? 1000 : $resolver->calculateUnitPrice($rule, 1000, 1000);
            self::assertLessThanOrEqual($last, $price, sprintf('quantity %d', $quantity));
            $last = $price;
        }
    }

    /** @param list<TierPriceRuleData> $rules */
    private function resolve(array $rules, TierPriceContext $context): ?TierPriceRuleData
    {
        return (new TierPriceResolver())->resolve($rules, $context, 1000, 1000);
    }

    private function percentage(int $id, int $minQuantity, int $basisPoints, ?int $variant = null, ?int $group = null, ?int $taxon = null, ?int $level = null, string $basis = TierPriceRuleInterface::BASIS_LINE, string $cumulation = TierPriceRuleInterface::CUMULATION_BEST_OF, ?string $endsAt = null): TierPriceRuleData
    {
        return new TierPriceRuleData($id, $variant, $group, $basis, $minQuantity, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, $basisPoints, null, null === $endsAt ? null : new \DateTimeImmutable($endsAt), $taxon, $level, $cumulation);
    }

    /** @param array<int, int>|null $taxonQuantities */
    private function context(int $quantity, ?int $group = null, ?array $taxonQuantities = null): TierPriceContext
    {
        return new TierPriceContext(self::VARIANT, $group, $quantity, $quantity, new \DateTimeImmutable('2030-01-15 12:00:00'), $taxonQuantities);
    }
}
