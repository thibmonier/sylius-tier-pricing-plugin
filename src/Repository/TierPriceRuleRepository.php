<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleData;

class TierPriceRuleRepository extends EntityRepository implements TierPriceRuleRepositoryInterface
{
    public function findCandidateData(ChannelInterface $channel, ProductInterface $product, ?CustomerGroupInterface $customerGroup): array
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->select('o.id', 'o.scope', 'IDENTITY(o.variant) AS variantId', 'IDENTITY(o.customerGroup) AS customerGroupId', 'o.quantityBasis', 'o.minQuantity', 'o.type', 'o.value', 'o.startsAt', 'o.endsAt', 'o.cumulation', 'taxon.id AS taxonId', 'taxon.level AS taxonLevel')
            ->leftJoin('o.variant', 'variant')
            ->leftJoin('o.taxon', 'taxon')
            ->andWhere('o.channel = :channel')
            ->andWhere('o.enabled = true')
            // Taxon rules: the product is in the taxon or in one of its children, through any of
            // its taxons (nested set of the taxon tree).
            ->andWhere(sprintf(
                'o.product = :product OR variant.product = :product OR (o.taxon IS NOT NULL AND EXISTS (SELECT productTaxon.id FROM %s productTaxon INNER JOIN productTaxon.taxon productTaxonTaxon WHERE productTaxon.product = :product AND productTaxonTaxon.root = taxon.root AND productTaxonTaxon.left >= taxon.left AND productTaxonTaxon.right <= taxon.right))',
                ProductTaxonInterface::class,
            ))
            ->setParameter('channel', $channel)
            ->setParameter('product', $product)
            ->orderBy('o.id', 'ASC')
        ;
        if (null === $customerGroup) {
            $queryBuilder->andWhere('o.customerGroup IS NULL');
        } else {
            $queryBuilder->andWhere('o.customerGroup IS NULL OR o.customerGroup = :customerGroup')->setParameter('customerGroup', $customerGroup);
        }

        /** @var list<array{id: int|string, scope: string, variantId: int|string|null, customerGroupId: int|string|null, quantityBasis: string, minQuantity: int|string, type: string, value: int|string, startsAt: \DateTimeInterface|null, endsAt: \DateTimeInterface|null, cumulation: string, taxonId: int|string|null, taxonLevel: int|string|null}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(static fn (array $row): TierPriceRuleData => new TierPriceRuleData(
            (int) $row['id'],
            TierPriceRuleInterface::SCOPE_VARIANT === $row['scope'] && null !== $row['variantId'] ? (int) $row['variantId'] : null,
            null === $row['customerGroupId'] ? null : (int) $row['customerGroupId'],
            $row['quantityBasis'],
            (int) $row['minQuantity'],
            $row['type'],
            (int) $row['value'],
            null === $row['startsAt'] ? null : \DateTimeImmutable::createFromInterface($row['startsAt']),
            null === $row['endsAt'] ? null : \DateTimeImmutable::createFromInterface($row['endsAt']),
            TierPriceRuleInterface::SCOPE_TAXON === $row['scope'] && null !== $row['taxonId'] ? (int) $row['taxonId'] : null,
            TierPriceRuleInterface::SCOPE_TAXON === $row['scope'] && null !== $row['taxonLevel'] ? (int) $row['taxonLevel'] : null,
            $row['cumulation'],
        ), $rows);
    }

    public function findConflicting(TierPriceRuleInterface $rule): array
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->andWhere('o.channel = :channel')
            ->andWhere('o.scope = :scope')
            ->andWhere('o.minQuantity = :minQuantity')
            ->setParameter('channel', $rule->getChannel())
            ->setParameter('scope', $rule->getScope())
            ->setParameter('minQuantity', $rule->getMinQuantity())
        ;
        // NULL never equals NULL in SQL (nor in a unique index on MySQL, MariaDB and PostgreSQL
        // < 15): the "everybody" rules are compared with IS NULL.
        if (null === $rule->getCustomerGroup()) {
            $queryBuilder->andWhere('o.customerGroup IS NULL');
        } else {
            $queryBuilder->andWhere('o.customerGroup = :customerGroup')->setParameter('customerGroup', $rule->getCustomerGroup());
        }
        if (TierPriceRuleInterface::SCOPE_VARIANT === $rule->getScope()) {
            $queryBuilder->andWhere('o.variant = :variant')->setParameter('variant', $rule->getVariant());
        } elseif (TierPriceRuleInterface::SCOPE_TAXON === $rule->getScope()) {
            $queryBuilder->andWhere('o.taxon = :taxon')->setParameter('taxon', $rule->getTaxon());
        } else {
            $queryBuilder->andWhere('o.product = :product')->setParameter('product', $rule->getProduct());
        }
        if (null !== $rule->getId()) {
            $queryBuilder->andWhere('o.id <> :id')->setParameter('id', $rule->getId());
        }
        // Date ranges [startsAt, endsAt) overlap; null = open.
        if (null !== $rule->getEndsAt()) {
            $queryBuilder->andWhere('o.startsAt IS NULL OR o.startsAt < :endsAt')->setParameter('endsAt', $rule->getEndsAt());
        }
        if (null !== $rule->getStartsAt()) {
            $queryBuilder->andWhere('o.endsAt IS NULL OR o.endsAt > :startsAt')->setParameter('startsAt', $rule->getStartsAt());
        }

        /** @var list<TierPriceRuleInterface> $result */
        $result = $queryBuilder->getQuery()->getResult();

        return $result;
    }

    public function findByProduct(ProductInterface $product): array
    {
        /** @var list<TierPriceRuleInterface> $result */
        $result = $this->createQueryBuilder('o')
            ->leftJoin('o.variant', 'variant')
            ->andWhere('o.product = :product OR variant.product = :product')
            ->setParameter('product', $product)
            ->addOrderBy('o.channel', 'ASC')
            ->addOrderBy('o.minQuantity', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $result;
    }

    public function createListQueryBuilder(?string $localeCode = null): QueryBuilder
    {
        // Every association the grid row shows is in the list query (no query per row or per
        // product): to-one associations, and the product translations of the admin locale only
        // (one row per rule, so the pagination is not affected).
        $queryBuilder = $this->createQueryBuilder('o')
            ->addSelect('channel', 'baseCurrency', 'customerGroup', 'product', 'variant', 'variantProduct', 'taxon')
            ->innerJoin('o.channel', 'channel')
            ->leftJoin('channel.baseCurrency', 'baseCurrency')
            ->leftJoin('o.customerGroup', 'customerGroup')
            ->leftJoin('o.product', 'product')
            ->leftJoin('o.variant', 'variant')
            ->leftJoin('variant.product', 'variantProduct')
            ->leftJoin('o.taxon', 'taxon')
        ;
        if (null !== $localeCode) {
            $queryBuilder
                ->addSelect('productTranslation', 'variantProductTranslation', 'taxonTranslation')
                ->leftJoin('product.translations', 'productTranslation', 'WITH', 'productTranslation.locale = :localeCode')
                ->leftJoin('variantProduct.translations', 'variantProductTranslation', 'WITH', 'variantProductTranslation.locale = :localeCode')
                ->leftJoin('taxon.translations', 'taxonTranslation', 'WITH', 'taxonTranslation.locale = :localeCode')
                ->setParameter('localeCode', $localeCode)
            ;
        }

        return $queryBuilder;
    }
}
