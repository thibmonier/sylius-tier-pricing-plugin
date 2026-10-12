<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

/**
 * Immutable copy of an enabled rule, as cached and read by the resolver (no Doctrine entity in
 * the cache, no lazy loading during the price calculation).
 */
final class TierPriceRuleData
{
    /**
     * @param int|null $variantId null for a product-wide or taxon rule
     * @param int|null $taxonId the taxon of a taxon rule (the product is in it or in one of its children)
     * @param int|null $taxonLevel depth of that taxon (0 for a root): the deepest taxon wins
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $variantId,
        public readonly ?int $customerGroupId,
        public readonly string $quantityBasis,
        public readonly int $minQuantity,
        public readonly string $type,
        public readonly int $value,
        public readonly ?\DateTimeImmutable $startsAt = null,
        public readonly ?\DateTimeImmutable $endsAt = null,
        public readonly ?int $taxonId = null,
        public readonly ?int $taxonLevel = null,
        public readonly string $cumulation = TierPriceRuleInterface::CUMULATION_BEST_OF,
    ) {
    }

    public static function fromRule(TierPriceRuleInterface $rule): self
    {
        $variantId = TierPriceRuleInterface::SCOPE_VARIANT === $rule->getScope() ? $rule->getVariant()?->getId() : null;
        $groupId = $rule->getCustomerGroup()?->getId();
        $taxon = TierPriceRuleInterface::SCOPE_TAXON === $rule->getScope() ? $rule->getTaxon() : null;
        $taxonId = $taxon?->getId();

        return new self(
            (int) $rule->getId(),
            is_int($variantId) ? $variantId : null,
            is_int($groupId) ? $groupId : null,
            $rule->getQuantityBasis(),
            $rule->getMinQuantity(),
            $rule->getType(),
            $rule->getValue(),
            null === $rule->getStartsAt() ? null : \DateTimeImmutable::createFromInterface($rule->getStartsAt()),
            null === $rule->getEndsAt() ? null : \DateTimeImmutable::createFromInterface($rule->getEndsAt()),
            is_int($taxonId) ? $taxonId : null,
            null === $taxon ? null : $taxon->getLevel(),
            $rule->getCumulation(),
        );
    }

    public function isVariantScoped(): bool
    {
        return null !== $this->variantId;
    }

    public function isTaxonScoped(): bool
    {
        return null !== $this->taxonId;
    }

    public function isActiveAt(\DateTimeInterface $now): bool
    {
        return (null === $this->startsAt || $this->startsAt <= $now) && (null === $this->endsAt || $this->endsAt > $now);
    }
}
