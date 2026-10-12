<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceContext;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceResolver;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleData;

final class TierPriceResolverTest extends TestCase
{
    private const VARIANT = 7;

    private const GROUP = 3;

    public function testNoRuleBelowTheFirstThreshold(): void
    {
        self::assertNull($this->resolve([$this->rule(1, 10, 900)], $this->context(9)));
    }

    public function testTheBestThresholdReachedWins(): void
    {
        $rules = [$this->rule(1, 10, 900), $this->rule(2, 50, 700), $this->rule(3, 25, 800)];

        self::assertSame(1, $this->resolve($rules, $this->context(10))?->id);
        self::assertSame(3, $this->resolve($rules, $this->context(49))?->id);
        self::assertSame(2, $this->resolve($rules, $this->context(500))?->id);
    }

    public function testTheLowestPriceWinsWhateverTheSpecificity(): void
    {
        // Regression: a group rule at 20+ for 9.50 must not raise the price of a group customer
        // above the 8.00 everybody rule at 10+ (20 pieces would cost more than 19).
        $everybody = $this->rule(1, 10, 800);
        $group = $this->rule(2, 20, 950, group: self::GROUP);
        $variant = $this->rule(3, 30, 990, variant: self::VARIANT);

        foreach ([10, 19, 20, 30, 100] as $quantity) {
            self::assertSame(1, $this->resolve([$everybody, $group, $variant], $this->context($quantity, group: self::GROUP))?->id, sprintf('quantity %d', $quantity));
        }
    }

    public function testSpecificityBreaksTies(): void
    {
        $product = $this->rule(1, 10, 800);
        $productGroup = $this->rule(2, 10, 800, group: self::GROUP);
        $variant = $this->rule(3, 5, 800, variant: self::VARIANT);
        $otherVariant = $this->rule(4, 2, 100, variant: 99);

        self::assertSame(3, $this->resolve([$product, $productGroup, $variant, $otherVariant], $this->context(10, group: self::GROUP))?->id);
        self::assertSame(2, $this->resolve([$product, $productGroup], $this->context(10, group: self::GROUP))?->id);
        self::assertSame(1, $this->resolve([$product, $productGroup], $this->context(10))?->id);
        // Another group does not get the group rule, nor the rule of another variant.
        self::assertNull($this->resolve([$productGroup, $otherVariant], $this->context(100, group: 4)));
    }

    public function testThePriceNeverGoesUpWithTheQuantity(): void
    {
        $rules = [
            $this->rule(1, 5, 950),
            $this->rule(2, 10, 990, group: self::GROUP),
            $this->rule(3, 20, 970, variant: self::VARIANT),
            $this->rule(4, 40, 900, basis: TierPriceRuleInterface::BASIS_PRODUCT),
            $this->rule(5, 60, 980, variant: self::VARIANT, group: self::GROUP),
        ];
        $resolver = new TierPriceResolver();
        $previous = 1000;
        for ($quantity = 1; $quantity <= 100; ++$quantity) {
            $rule = $this->resolve($rules, $this->context($quantity, group: self::GROUP));
            $price = null === $rule ? 1000 : $resolver->calculateUnitPrice($rule, 1000, 1000);
            self::assertLessThanOrEqual($previous, $price, sprintf('quantity %d', $quantity));
            $previous = $price;
        }
    }

    public function testTheQuantityBasisOfEachRuleIsUsed(): void
    {
        $line = $this->rule(1, 10, 900);
        $product = $this->rule(2, 10, 900, basis: TierPriceRuleInterface::BASIS_PRODUCT);

        self::assertSame(2, $this->resolve([$line, $product], $this->context(4, productQuantity: 10))?->id);
        self::assertNull($this->resolve([$line], $this->context(4, productQuantity: 10)));
    }

    public function testDatesAreAHalfOpenRange(): void
    {
        $rule = $this->rule(1, 2, 900, startsAt: '2030-01-01 00:00:00', endsAt: '2030-02-01 00:00:00');

        self::assertNull($this->resolve([$rule], $this->context(5, now: '2029-12-31 23:59:59')));
        self::assertNotNull($this->resolve([$rule], $this->context(5, now: '2030-01-01 00:00:00')));
        self::assertNull($this->resolve([$rule], $this->context(5, now: '2030-02-01 00:00:00')));
    }

    public function testTiesAreBrokenByTheOldestRule(): void
    {
        self::assertSame(4, $this->resolve([$this->rule(9, 10, 900), $this->rule(4, 10, 900)], $this->context(10))?->id);
    }

    /** @return iterable<string, array{string, int, int, int, int, int}> */
    public static function prices(): iterable
    {
        yield 'percentage of the regular price' => [TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, 1000, 1000, 0, 900];
        yield 'rounded half up' => [TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 333, 1005, 1005, 0, 972];
        yield 'best of tier and catalog promotion' => [TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500, 1000, 900, 0, 900];
        yield 'percentage on the regular price, not on the promotion price' => [TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 2000, 1000, 900, 0, 800];
        yield 'fixed price' => [TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 750, 1000, 1000, 0, 750];
        yield 'fixed price above the current price' => [TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 1200, 1000, 1000, 0, 1000];
        yield 'minimum price floor' => [TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 5000, 1000, 1000, 800, 800];
        yield 'never negative' => [TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 20000, 1000, 1000, 0, 0];
    }

    #[DataProvider('prices')]
    public function testTheUnitPrice(string $type, int $value, int $base, int $current, int $minimum, int $expected): void
    {
        $rule = new TierPriceRuleData(1, null, null, TierPriceRuleInterface::BASIS_LINE, 2, $type, $value);

        self::assertSame($expected, (new TierPriceResolver())->calculateUnitPrice($rule, $base, $current, $minimum));
    }

    /** @param list<TierPriceRuleData> $rules */
    private function resolve(array $rules, TierPriceContext $context): ?TierPriceRuleData
    {
        return (new TierPriceResolver())->resolve($rules, $context, 1000, 1000);
    }

    /** A fixed unit price rule (regular price 10.00 in the tests). */
    private function rule(int $id, int $minQuantity, int $price, ?int $variant = null, ?int $group = null, string $basis = TierPriceRuleInterface::BASIS_LINE, ?string $startsAt = null, ?string $endsAt = null): TierPriceRuleData
    {
        return new TierPriceRuleData(
            $id,
            $variant,
            $group,
            $basis,
            $minQuantity,
            TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE,
            $price,
            null === $startsAt ? null : new \DateTimeImmutable($startsAt),
            null === $endsAt ? null : new \DateTimeImmutable($endsAt),
        );
    }

    private function context(int $lineQuantity, ?int $group = null, ?int $productQuantity = null, string $now = '2030-01-15 12:00:00'): TierPriceContext
    {
        return new TierPriceContext(self::VARIANT, $group, $lineQuantity, $productQuantity ?? $lineQuantity, new \DateTimeImmutable($now));
    }
}
