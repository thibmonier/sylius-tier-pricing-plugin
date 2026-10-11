<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Api;

/**
 * Shop API read model: price breaks of a variant for the current channel and customer, amounts
 * in the channel base currency (cents), with taxes in consumer channels, without taxes in the
 * `b2b_channels`.
 */
final class TierPriceTableView
{
    /**
     * @param list<array{minQuantity: int, unitPrice: int, mixAndMatch: bool}> $tiers
     */
    public function __construct(
        public readonly string $code,
        public readonly string $currencyCode,
        public readonly bool $taxIncluded,
        public readonly array $tiers,
    ) {
    }
}
