<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Repository\TierPriceRuleRepositoryInterface;

final class ValidTierPriceRuleValidator extends ConstraintValidator
{
    public function __construct(private readonly TierPriceRuleRepositoryInterface $repository)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTierPriceRule) {
            throw new UnexpectedTypeException($constraint, ValidTierPriceRule::class);
        }
        if (!$value instanceof TierPriceRuleInterface) {
            return;
        }

        $valid = true;
        // Exactly one target, the one of the scope (also enforced by a CHECK constraint).
        $targets = ['product' => $value->getProduct(), 'variant' => $value->getVariant(), 'taxon' => $value->getTaxon()];
        $expected = match ($value->getScope()) {
            TierPriceRuleInterface::SCOPE_VARIANT => 'variant',
            TierPriceRuleInterface::SCOPE_TAXON => 'taxon',
            default => 'product',
        };
        foreach ($targets as $field => $target) {
            if ($field === $expected && null === $target) {
                $this->context->buildViolation($constraint->targetMissing)->atPath($field)->addViolation();
                $valid = false;
            } elseif ($field !== $expected && null !== $target) {
                $this->context->buildViolation($constraint->targetMismatch)->atPath($field)->addViolation();
                $valid = false;
            }
        }
        // Counting the taxon only makes sense for a taxon rule.
        if (TierPriceRuleInterface::BASIS_TAXON === $value->getQuantityBasis() && TierPriceRuleInterface::SCOPE_TAXON !== $value->getScope()) {
            $this->context->buildViolation($constraint->taxonBasis)->atPath('quantityBasis')->addViolation();
        }
        if (TierPriceRuleInterface::TYPE_PERCENTAGE_OFF === $value->getType() && ($value->getValue() <= 0 || $value->getValue() > 10000)) {
            $this->context->buildViolation($constraint->percentageRange)->atPath('value')->addViolation();
        }
        if (TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE === $value->getType() && ($value->getValue() < 0 || $value->getValue() > TierPriceRuleInterface::MAX_AMOUNT)) {
            $this->context->buildViolation($constraint->amountRange)
                ->setParameter('%max%', number_format(TierPriceRuleInterface::MAX_AMOUNT / 100, 2, '.', ''))
                ->atPath('value')
                ->addViolation()
            ;
        }
        if (null !== $value->getStartsAt() && null !== $value->getEndsAt() && $value->getEndsAt() <= $value->getStartsAt()) {
            $this->context->buildViolation($constraint->datesOrder)->atPath('endsAt')->addViolation();
        }

        if ($valid && null !== $value->getChannel() && [] !== $this->repository->findConflicting($value)) {
            $this->context->buildViolation($constraint->duplicate)
                ->setParameter('%quantity%', (string) $value->getMinQuantity())
                ->atPath('minQuantity')
                ->addViolation()
            ;
        }
    }
}
