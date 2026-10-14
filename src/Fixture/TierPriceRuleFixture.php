<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Factory\TierPriceRuleFactoryInterface;

/**
 * Demo price breaks on the first `amount` products of every channel:
 * - every product: 5+ -5 %, 10+ -10 %, 25+ -15 % (per line);
 * - customers of the `wholesale_group` group: 10+ -20 %;
 * - products with several variants: 12+ -12 % mix and match (every variant counts);
 * - the first variant of the first product: fixed unit price at 50+ (70 % of its price).
 */
final class TierPriceRuleFixture extends AbstractFixture
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TierPriceRuleFactoryInterface $factory,
    ) {
    }

    public function getName(): string
    {
        return 'tmonier_tier_pricing';
    }

    /** @param array{amount: int, wholesale_group: string|null} $options */
    public function load(array $options): void
    {
        /** @var list<ChannelInterface> $channels */
        $channels = $this->entityManager->getRepository(ChannelInterface::class)->findAll();
        $group = null === $options['wholesale_group'] ? null : $this->entityManager->getRepository(CustomerGroupInterface::class)->findOneBy(['code' => $options['wholesale_group']]);

        foreach ($channels as $channel) {
            $products = $this->products($channel, $options['amount']);
            foreach ($products as $index => $product) {
                foreach ([5 => 500, 10 => 1000, 25 => 1500] as $minQuantity => $basisPoints) {
                    $this->rule($channel, $product, null, $minQuantity, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, $basisPoints);
                }
                if ($group instanceof CustomerGroupInterface) {
                    $this->rule($channel, $product, null, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 2000, $group);
                }
                if ($product->getEnabledVariants()->count() > 1) {
                    $this->rule($channel, $product, null, 12, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1200, null, TierPriceRuleInterface::BASIS_PRODUCT);
                }
                $variant = $product->getEnabledVariants()->first();
                $price = $variant instanceof ProductVariantInterface ? $variant->getChannelPricingForChannel($channel)?->getPrice() : null;
                if (0 === $index && $variant instanceof ProductVariantInterface && null !== $price) {
                    $this->rule($channel, null, $variant, 50, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, (int) round($price * 0.7));
                }
            }
        }

        $this->entityManager->flush();
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->integerNode('amount')->defaultValue(10)->min(0)->end()
                ->scalarNode('wholesale_group')->defaultValue('wholesale')->end()
            ->end()
        ;
    }

    /** @return list<ProductInterface> enabled products of the channel with a price */
    private function products(ChannelInterface $channel, int $amount): array
    {
        /** @var list<ProductInterface> $products */
        $products = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p')
            ->from(ProductInterface::class, 'p')
            ->innerJoin('p.channels', 'channel')
            ->innerJoin('p.variants', 'v')
            ->innerJoin('v.channelPricings', 'pricing')
            ->andWhere('channel = :channel')
            ->andWhere('p.enabled = true')
            ->andWhere('pricing.channelCode = :channelCode')
            ->setParameter('channel', $channel)
            ->setParameter('channelCode', $channel->getCode())
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($amount)
            ->getQuery()
            ->getResult()
        ;

        return $products;
    }

    private function rule(ChannelInterface $channel, ?ProductInterface $product, ?ProductVariantInterface $variant, int $minQuantity, string $type, int $value, ?CustomerGroupInterface $group = null, string $basis = TierPriceRuleInterface::BASIS_LINE): void
    {
        $rule = $this->factory->createNew();
        $rule->setChannel($channel);
        $rule->setCustomerGroup($group);
        $rule->setScope($variant instanceof \Sylius\Component\Core\Model\ProductVariantInterface ? TierPriceRuleInterface::SCOPE_VARIANT : TierPriceRuleInterface::SCOPE_PRODUCT);
        $rule->setProduct($product);
        $rule->setVariant($variant);
        $rule->setQuantityBasis($basis);
        $rule->setMinQuantity($minQuantity);
        $rule->setType($type);
        $rule->setValue($value);
        $this->entityManager->persist($rule);
    }
}
