<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Csv;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final readonly class TierPriceRuleCsvExporter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param resource $stream
     *
     * @return int number of rules written
     */
    public function export($stream, ?string $channelCode = null): int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('o', 'channel', 'customerGroup', 'product', 'variant', 'taxon')
            ->from(TierPriceRuleInterface::class, 'o')
            ->innerJoin('o.channel', 'channel')
            ->leftJoin('o.customerGroup', 'customerGroup')
            ->leftJoin('o.product', 'product')
            ->leftJoin('o.variant', 'variant')
            ->leftJoin('o.taxon', 'taxon')
            ->orderBy('o.id', 'ASC')
        ;
        if (null !== $channelCode) {
            $queryBuilder->andWhere('channel.code = :channel')->setParameter('channel', $channelCode);
        }

        fputcsv($stream, TierPriceRuleCsvFormat::HEADER, escape: '');
        $count = 0;
        /** @var TierPriceRuleInterface $rule */
        foreach ($queryBuilder->getQuery()->toIterable() as $rule) {
            fputcsv($stream, [
                (string) $rule->getChannel()?->getCode(),
                (string) $rule->getCustomerGroup()?->getCode(),
                $rule->getScope(),
                TierPriceRuleInterface::SCOPE_PRODUCT === $rule->getScope() ? (string) $rule->getProduct()?->getCode() : '',
                TierPriceRuleInterface::SCOPE_VARIANT === $rule->getScope() ? (string) $rule->getVariant()?->getCode() : '',
                $rule->getQuantityBasis(),
                (string) $rule->getMinQuantity(),
                $rule->getType(),
                TierPriceRuleCsvFormat::formatValue($rule->getType(), $rule->getValue()),
                $rule->isEnabled() ? '1' : '0',
                $rule->getStartsAt()?->format(\DATE_ATOM) ?? '',
                $rule->getEndsAt()?->format(\DATE_ATOM) ?? '',
                TierPriceRuleInterface::SCOPE_TAXON === $rule->getScope() ? (string) $rule->getTaxon()?->getCode() : '',
                $rule->getCumulation(),
            ], escape: '');
            ++$count;
            if (0 === $count % 500) {
                $this->entityManager->clear();
            }
        }

        return $count;
    }
}
