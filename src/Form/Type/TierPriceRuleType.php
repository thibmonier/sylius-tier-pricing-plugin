<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Form\Type;

use Sylius\Bundle\AdminBundle\Form\Type\ProductAutocompleteType;
use Sylius\Bundle\AdminBundle\Form\Type\ProductVariantAutocompleteType;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\CustomerBundle\Form\Type\CustomerGroupChoiceType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Range;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

/**
 * The value of a rule is entered as an amount (fixed unit price) or as a percentage, and
 * stored as an integer: cents or basis points.
 */
final class TierPriceRuleType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channel', ChannelChoiceType::class, [
                'label' => 'sylius.ui.channel',
                'placeholder' => false,
            ])
            ->add('customerGroup', CustomerGroupChoiceType::class, [
                'label' => 'sylius.ui.customer_group',
                'required' => false,
                'placeholder' => 'tmonier_tier_pricing.form.all_customers',
            ])
            ->add('scope', ChoiceType::class, [
                'label' => 'tmonier_tier_pricing.form.scope',
                'choices' => [
                    'tmonier_tier_pricing.scope.product' => TierPriceRuleInterface::SCOPE_PRODUCT,
                    'tmonier_tier_pricing.scope.variant' => TierPriceRuleInterface::SCOPE_VARIANT,
                ],
                'expanded' => true,
            ])
            ->add('product', ProductAutocompleteType::class, [
                'label' => 'sylius.ui.product',
                'required' => false,
                'help' => 'tmonier_tier_pricing.form.product_help',
            ])
            ->add('variant', ProductVariantAutocompleteType::class, [
                'label' => 'sylius.ui.variant',
                'required' => false,
                'help' => 'tmonier_tier_pricing.form.variant_help',
            ])
            ->add('quantityBasis', ChoiceType::class, [
                'label' => 'tmonier_tier_pricing.form.quantity_basis',
                'choices' => [
                    'tmonier_tier_pricing.basis.line' => TierPriceRuleInterface::BASIS_LINE,
                    'tmonier_tier_pricing.basis.product' => TierPriceRuleInterface::BASIS_PRODUCT,
                ],
                'expanded' => true,
            ])
            ->add('minQuantity', IntegerType::class, [
                'label' => 'tmonier_tier_pricing.form.min_quantity',
                'attr' => ['min' => TierPriceRuleInterface::MIN_QUANTITY],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'tmonier_tier_pricing.form.type',
                'choices' => [
                    'tmonier_tier_pricing.type.percentage_off' => TierPriceRuleInterface::TYPE_PERCENTAGE_OFF,
                    'tmonier_tier_pricing.type.fixed_unit_price' => TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE,
                ],
                'expanded' => true,
            ])
            ->add('percentage', NumberType::class, [
                'label' => 'tmonier_tier_pricing.form.percentage',
                'mapped' => false,
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0.01, 'max' => 100, 'step' => 0.01],
                'constraints' => [new Range(min: 0.01, max: 100, groups: ['sylius'])],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'tmonier_tier_pricing.form.amount',
                'help' => 'tmonier_tier_pricing.form.amount_help',
                'mapped' => false,
                'required' => false,
                'currency' => false,
                'divisor' => 100,
                'constraints' => [new Range(min: 0, max: TierPriceRuleInterface::MAX_AMOUNT / 100, groups: ['sylius'])],
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'sylius.ui.enabled',
                'required' => false,
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'sylius.ui.starts_at',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'sylius.ui.ends_at',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
                $rule = $event->getData();
                if (!$rule instanceof TierPriceRuleInterface || null === $rule->getId()) {
                    return;
                }
                $form = $event->getForm();
                if (TierPriceRuleInterface::TYPE_PERCENTAGE_OFF === $rule->getType()) {
                    $form->get('percentage')->setData($rule->getValue() / 100);
                } else {
                    $form->get('amount')->setData($rule->getValue());
                }
            })
            ->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
                $rule = $event->getData();
                if (!$rule instanceof TierPriceRuleInterface) {
                    return;
                }
                $form = $event->getForm();
                if (TierPriceRuleInterface::TYPE_PERCENTAGE_OFF === $rule->getType()) {
                    $percentage = $form->get('percentage')->getData();
                    $rule->setValue(is_numeric($percentage) ? (int) round((float) $percentage * 100) : 0);
                } else {
                    $amount = $form->get('amount')->getData();
                    $rule->setValue(is_numeric($amount) ? (int) $amount : -1);
                }
                // Only the target of the scope is kept.
                if (TierPriceRuleInterface::SCOPE_VARIANT === $rule->getScope()) {
                    $rule->setProduct(null);
                } else {
                    $rule->setVariant(null);
                }
            })
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_tier_pricing_tier_price_rule';
    }
}
