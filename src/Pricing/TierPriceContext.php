<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

/**
 * What the resolver needs to know about one cart line (or one simulated quantity on a product
 * page): the variant, the customer group and both quantities a rule can count.
 */
final class TierPriceContext
{
    public function __construct(
        public readonly int $variantId,
        public readonly ?int $customerGroupId,
        /** Quantity of the line (this variant). */
        public readonly int $lineQuantity,
        /** Quantity of every line of the same product (mix and match). */
        public readonly int $productQuantity,
        public readonly \DateTimeInterface $now,
    ) {
    }

    public function withAddedQuantity(int $quantity): self
    {
        return new self($this->variantId, $this->customerGroupId, $this->lineQuantity + $quantity, $this->productQuantity + $quantity, $this->now);
    }
}
