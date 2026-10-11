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
     * @param int|null $variantId null for a product-wide rule
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
    ) {
    }

    public static function fromRule(TierPriceRuleInterface $rule): self
    {
        $variantId = TierPriceRuleInterface::SCOPE_VARIANT === $rule->getScope() ? $rule->getVariant()?->getId() : null;
        $groupId = $rule->getCustomerGroup()?->getId();

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
        );
    }

    public function isVariantScoped(): bool
    {
        return null !== $this->variantId;
    }

    public function isActiveAt(\DateTimeInterface $now): bool
    {
        return (null === $this->startsAt || $this->startsAt <= $now) && (null === $this->endsAt || $this->endsAt > $now);
    }
}
