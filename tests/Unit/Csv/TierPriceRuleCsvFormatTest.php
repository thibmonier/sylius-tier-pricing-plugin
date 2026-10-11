<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Csv;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvFormat;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final class TierPriceRuleCsvFormatTest extends TestCase
{
    public function testValuesAreReadAsCentsOrBasisPoints(): void
    {
        self::assertSame(1250, TierPriceRuleCsvFormat::parseValue('12.50'));
        self::assertSame(1250, TierPriceRuleCsvFormat::parseValue(' 12,5 '));
        self::assertSame(700, TierPriceRuleCsvFormat::parseValue('7'));
        self::assertNull(TierPriceRuleCsvFormat::parseValue('12.505'));
        self::assertNull(TierPriceRuleCsvFormat::parseValue('ten'));
        self::assertNull(TierPriceRuleCsvFormat::parseValue(''));
    }

    public function testValuesAreWrittenAsAmountsOrPercentages(): void
    {
        self::assertSame('12.50', TierPriceRuleCsvFormat::formatValue(TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 1250));
        self::assertSame('12.5', TierPriceRuleCsvFormat::formatValue(TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1250));
        self::assertSame('10', TierPriceRuleCsvFormat::formatValue(TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000));
        self::assertSame('0.00', TierPriceRuleCsvFormat::formatValue(TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 0));
    }
}
