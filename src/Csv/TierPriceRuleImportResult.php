<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Csv;

final class TierPriceRuleImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $deleted = 0;

    /** @var list<string> "line N: message" */
    public array $errors = [];

    public bool $committed = false;

    public function isSuccessful(): bool
    {
        return [] === $this->errors;
    }
}
