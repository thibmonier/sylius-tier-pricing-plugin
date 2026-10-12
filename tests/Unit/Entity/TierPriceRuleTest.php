<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRule;

final class TierPriceRuleTest extends TestCase
{
    public function testDatesWithAnOffsetKeepTheirInstantInTheApplicationTimezone(): void
    {
        $rule = new TierPriceRule();
        $rule->setStartsAt(new \DateTimeImmutable('2030-01-01T00:00:00+05:00'));
        $rule->setEndsAt(new \DateTime('2030-02-01T00:00:00-03:00'));

        $timezone = date_default_timezone_get();
        self::assertSame($timezone, $rule->getStartsAt()?->getTimezone()->getName());
        self::assertSame((new \DateTimeImmutable('2029-12-31T19:00:00Z'))->getTimestamp(), $rule->getStartsAt()->getTimestamp());
        self::assertSame($timezone, $rule->getEndsAt()?->getTimezone()->getName());
        self::assertSame((new \DateTimeImmutable('2030-02-01T03:00:00Z'))->getTimestamp(), $rule->getEndsAt()->getTimestamp());
    }

    public function testADateAlreadyInTheApplicationTimezoneIsKept(): void
    {
        $date = new \DateTime('2030-01-01 10:00:00');
        $rule = new TierPriceRule();
        $rule->setStartsAt($date);
        $rule->setEndsAt(null);

        self::assertSame($date, $rule->getStartsAt(), 'same instance: no needless Doctrine update');
        self::assertNull($rule->getEndsAt());
    }
}
