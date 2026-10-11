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
        self::assertNull((new TierPriceResolver())->resolve([$this->rule(1, 10)], $this->context(9)));
    }

    public function testTheHighestThresholdReachedWins(): void
    {
        $rules = [$this->rule(1, 10), $this->rule(2, 50), $this->rule(3, 25)];

        self::assertSame(1, (new TierPriceResolver())->resolve($rules, $this->context(10))?->id);
        self::assertSame(3, (new TierPriceResolver())->resolve($rules, $this->context(49))?->id);
        self::assertSame(2, (new TierPriceResolver())->resolve($rules, $this->context(500))?->id);
    }

    public function testScopeFirstThenGroupThenThreshold(): void
    {
        $product = $this->rule(1, 50);
        $productGroup = $this->rule(2, 20, group: self::GROUP);
        $variant = $this->rule(3, 30, variant: self::VARIANT);
        $otherVariant = $this->rule(4, 2, variant: 99);
        $rules = [$product, $productGroup, $variant, $otherVariant];

        // A variant rule reached beats any product rule, even with a higher threshold.
        self::assertSame(3, (new TierPriceResolver())->resolve($rules, $this->context(60, group: self::GROUP))?->id);
        self::assertSame(3, (new TierPriceResolver())->resolve($rules, $this->context(55))?->id);
        // Below the variant threshold: the group rule beats the rule for everybody.
        self::assertSame(2, (new TierPriceResolver())->resolve($rules, $this->context(25, group: self::GROUP))?->id);
        self::assertSame(2, (new TierPriceResolver())->resolve([$product, $productGroup], $this->context(55, group: self::GROUP))?->id);
        self::assertNull((new TierPriceResolver())->resolve($rules, $this->context(25)));
        // Another group does not get the group rule.
        self::assertNull((new TierPriceResolver())->resolve([$productGroup], $this->context(100, group: 4)));
    }

    public function testTheQuantityBasisOfEachRuleIsUsed(): void
    {
        $line = $this->rule(1, 10);
        $product = $this->rule(2, 10, basis: TierPriceRuleInterface::BASIS_PRODUCT);

        self::assertSame(2, (new TierPriceResolver())->resolve([$line, $product], $this->context(4, productQuantity: 10))?->id);
        self::assertNull((new TierPriceResolver())->resolve([$line], $this->context(4, productQuantity: 10)));
    }

    public function testDatesAreAHalfOpenRange(): void
    {
        $rule = $this->rule(1, 2, startsAt: '2030-01-01 00:00:00', endsAt: '2030-02-01 00:00:00');

        self::assertNull((new TierPriceResolver())->resolve([$rule], $this->context(5, now: '2029-12-31 23:59:59')));
        self::assertNotNull((new TierPriceResolver())->resolve([$rule], $this->context(5, now: '2030-01-01 00:00:00')));
        self::assertNull((new TierPriceResolver())->resolve([$rule], $this->context(5, now: '2030-02-01 00:00:00')));
    }

    public function testTiesAreBrokenByTheOldestRule(): void
    {
        self::assertSame(4, (new TierPriceResolver())->resolve([$this->rule(9, 10), $this->rule(4, 10)], $this->context(10))?->id);
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

    private function rule(int $id, int $minQuantity, ?int $variant = null, ?int $group = null, string $basis = TierPriceRuleInterface::BASIS_LINE, ?string $startsAt = null, ?string $endsAt = null): TierPriceRuleData
    {
        return new TierPriceRuleData(
            $id,
            $variant,
            $group,
            $basis,
            $minQuantity,
            TierPriceRuleInterface::TYPE_PERCENTAGE_OFF,
            1000,
            null === $startsAt ? null : new \DateTimeImmutable($startsAt),
            null === $endsAt ? null : new \DateTimeImmutable($endsAt),
        );
    }

    private function context(int $lineQuantity, ?int $group = null, ?int $productQuantity = null, string $now = '2030-01-15 12:00:00'): TierPriceContext
    {
        return new TierPriceContext(self::VARIANT, $group, $lineQuantity, $productQuantity ?? $lineQuantity, new \DateTimeImmutable($now));
    }
}
