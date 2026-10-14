<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

/** Price breaks of a variant for a customer: row 1 is the price of one unit. */
final readonly class TierPriceTable
{
    /**
     * @param list<TierPriceRow> $rows
     */
    public function __construct(
        public array $rows,
        public ?TierPriceNudge $nudge = null,
        /** Unit price for the quantities already in the cart. */
        public ?int $currentUnitPrice = null,
    ) {
    }

    /** At least one price break besides the unit price. */
    public function hasTiers(): bool
    {
        return \count($this->rows) > 1;
    }

    public function hasMixAndMatch(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->mixAndMatch) {
                return true;
            }
        }

        return false;
    }

    public function hasTaxonBasis(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->taxonBasis) {
                return true;
            }
        }

        return false;
    }
}
