<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleData;

/**
 * @extends RepositoryInterface<TierPriceRuleInterface>
 */
interface TierPriceRuleRepositoryInterface extends RepositoryInterface
{
    /**
     * Enabled rules of the product (product-wide and variant rules) in the channel, for everybody
     * or for the group.
     *
     * @return list<TierPriceRuleData>
     */
    public function findCandidateData(ChannelInterface $channel, ProductInterface $product, ?CustomerGroupInterface $customerGroup): array;

    /**
     * Rules with the same channel, customer group, target and threshold whose dates overlap
     * the ones of `$rule` (other than `$rule` itself).
     *
     * @return list<TierPriceRuleInterface>
     */
    public function findConflicting(TierPriceRuleInterface $rule): array;

    /** @return list<TierPriceRuleInterface> rules of the product and of its variants */
    public function findByProduct(ProductInterface $product): array;

    /** Grid query; with the admin locale, product names are fetched in the same query. */
    public function createListQueryBuilder(?string $localeCode = null): QueryBuilder;
}
