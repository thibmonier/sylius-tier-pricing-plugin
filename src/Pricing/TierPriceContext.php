<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

/**
 * What the resolver needs to know about one cart line (or one simulated quantity on a product
 * page): the variant, the customer group, the quantities a rule can count and whether the
 * variant has a catalog promotion.
 */
final readonly class TierPriceContext
{
    /**
     * @param array<int, int>|null $taxonQuantities quantity of the lines of each taxon (products in the taxon or its children), by taxon id; null: unknown (simulated quantity), the product quantity is used
     */
    public function __construct(
        public int $variantId,
        public ?int $customerGroupId,
        /** Quantity of the line (this variant). */
        public int $lineQuantity,
        /** Quantity of every line of the same product (mix and match). */
        public int $productQuantity,
        public \DateTimeInterface $now,
        public ?array $taxonQuantities = null,
        /** The variant has a catalog promotion (`skip_if_promoted` rules do not apply). */
        public bool $promoted = false,
    ) {
    }

    public function withAddedQuantity(int $quantity): self
    {
        $taxonQuantities = null === $this->taxonQuantities ? null : array_map(static fn (int $taxonQuantity): int => $taxonQuantity + $quantity, $this->taxonQuantities);

        return new self($this->variantId, $this->customerGroupId, $this->lineQuantity + $quantity, $this->productQuantity + $quantity, $this->now, $taxonQuantities, $this->promoted);
    }

    public function withPromoted(bool $promoted): self
    {
        return new self($this->variantId, $this->customerGroupId, $this->lineQuantity, $this->productQuantity, $this->now, $this->taxonQuantities, $promoted);
    }

    /** Quantity counted by a rule of the given basis. */
    public function quantityFor(TierPriceRuleData $rule): int
    {
        return match ($rule->quantityBasis) {
            TierPriceRuleInterface::BASIS_PRODUCT => $this->productQuantity,
            TierPriceRuleInterface::BASIS_TAXON => null === $rule->taxonId || null === $this->taxonQuantities ? $this->productQuantity : max($this->productQuantity, $this->taxonQuantities[$rule->taxonId] ?? 0),
            default => $this->lineQuantity,
        };
    }
}
