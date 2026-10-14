<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

/**
 * Immutable copy of an enabled rule, as cached and read by the resolver (no Doctrine entity in
 * the cache, no lazy loading during the price calculation).
 */
final readonly class TierPriceRuleData
{
    /**
     * @param int|null $variantId null for a product-wide or taxon rule
     * @param int|null $taxonId the taxon of a taxon rule (the product is in it or in one of its children)
     * @param int|null $taxonLevel depth of that taxon (0 for a root): the deepest taxon wins
     */
    public function __construct(
        public int $id,
        public ?int $variantId,
        public ?int $customerGroupId,
        public string $quantityBasis,
        public int $minQuantity,
        public string $type,
        public int $value,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $endsAt = null,
        public ?int $taxonId = null,
        public ?int $taxonLevel = null,
        public string $cumulation = TierPriceRuleInterface::CUMULATION_BEST_OF,
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
            $rule->getStartsAt() instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($rule->getStartsAt()) : null,
            $rule->getEndsAt() instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($rule->getEndsAt()) : null,
            is_int($taxonId) ? $taxonId : null,
            $taxon?->getLevel(),
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
        return (!$this->startsAt instanceof \DateTimeImmutable || $this->startsAt <= $now) && (!$this->endsAt instanceof \DateTimeImmutable || $this->endsAt > $now);
    }
}
