<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Api;

/**
 * Shop API read model: price breaks of a variant for the current channel and customer, amounts
 * as stored in the channel base currency (cents), like the variant price; `taxIncluded` tells
 * the convention of the variant tax rate in the channel default zone (null when unknown).
 */
final class TierPriceTableView
{
    /**
     * `mixAndMatch`: every variant of the product counts; `taxonBasis`: every product of the
     * taxon of the rule (and of its children) counts.
     *
     * @param list<array{minQuantity: int, unitPrice: int, mixAndMatch: bool, taxonBasis: bool}> $tiers
     */
    public function __construct(
        public readonly string $code,
        public readonly string $currencyCode,
        public readonly ?bool $taxIncluded,
        public readonly array $tiers,
    ) {
    }
}
