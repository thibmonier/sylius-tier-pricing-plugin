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
        if (TierPriceRuleInterface::SCOPE_VARIANT === $value->getScope() && null === $value->getVariant()) {
            $this->context->buildViolation($constraint->targetMissing)->atPath('variant')->addViolation();
            $valid = false;
        }
        if (TierPriceRuleInterface::SCOPE_PRODUCT === $value->getScope() && null === $value->getProduct()) {
            $this->context->buildViolation($constraint->targetMissing)->atPath('product')->addViolation();
            $valid = false;
        }
        // Exactly one target, the one of the scope (also enforced by a CHECK constraint).
        if (TierPriceRuleInterface::SCOPE_VARIANT === $value->getScope() && null !== $value->getProduct()) {
            $this->context->buildViolation($constraint->targetMismatch)->atPath('product')->addViolation();
            $valid = false;
        }
        if (TierPriceRuleInterface::SCOPE_PRODUCT === $value->getScope() && null !== $value->getVariant()) {
            $this->context->buildViolation($constraint->targetMismatch)->atPath('variant')->addViolation();
            $valid = false;
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
