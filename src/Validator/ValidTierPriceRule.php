<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Consistency of a rule (target matching the scope, value range of the type, dates) and
 * uniqueness: one rule per channel, customer group (or everybody), target and threshold over
 * a period. Checked in PHP because a unique index treats NULL groups as distinct on MySQL,
 * MariaDB and PostgreSQL < 15.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidTierPriceRule extends Constraint
{
    public string $targetMissing = 'tmonier_tier_pricing.tier_price_rule.target_missing';

    public string $targetMismatch = 'tmonier_tier_pricing.tier_price_rule.target_mismatch';

    public string $percentageRange = 'tmonier_tier_pricing.tier_price_rule.percentage_range';

    public string $amountRange = 'tmonier_tier_pricing.tier_price_rule.amount_range';

    public string $datesOrder = 'tmonier_tier_pricing.tier_price_rule.dates_order';

    public string $duplicate = 'tmonier_tier_pricing.tier_price_rule.duplicate';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }

    public function validatedBy(): string
    {
        return 'tmonier_tier_pricing.validator.tier_price_rule';
    }
}
