<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Csv;

use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

/**
 * One rule per line. `value` is an amount in the channel base currency (`12.50`) for a fixed
 * unit price, a percentage (`7.5`) for a percentage off. Dates: ISO 8601 or empty.
 */
final class TierPriceRuleCsvFormat
{
    public const HEADER = ['channel', 'customer_group', 'scope', 'product', 'variant', 'quantity_basis', 'min_quantity', 'type', 'value', 'enabled', 'starts_at', 'ends_at'];

    public static function formatValue(string $type, int $value): string
    {
        $formatted = number_format($value / 100, 2, '.', '');

        return TierPriceRuleInterface::TYPE_PERCENTAGE_OFF === $type ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /** Cents or basis points; null when not a number. */
    public static function parseValue(string $value): ?int
    {
        $value = str_replace(',', '.', trim($value));
        // At most 10 integer digits: larger values are refused by the validator, not by the database.
        if (1 !== preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}
