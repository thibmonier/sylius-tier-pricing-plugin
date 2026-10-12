<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Model\ProductVariantInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleProviderInterface;

/**
 * Invalidates the cached rules of the products whose rules are created, changed (including a
 * move to another product) or deleted, once the flush succeeded. Taxon rules, changes of the
 * taxon tree and of the taxons of a product change the rules of many products: every cached
 * rule is invalidated (taxon rule or tree), or the rules of the product.
 */
final class TierPriceCacheInvalidator
{
    /** @var array<int, true> */
    private array $productIds = [];

    private bool $all = false;

    public function __construct(private readonly TierPriceRuleProviderInterface $ruleProvider)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        $entities = [...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()];
        foreach ($entities as $entity) {
            if ($entity instanceof ProductTaxonInterface) {
                $this->remember($entity->getProduct());

                continue;
            }
            if ($entity instanceof TaxonInterface) {
                // A move in the tree changes which products are under a taxon.
                $this->all = $this->all || null === $entity->getId() || array_intersect(['parent', 'left', 'right', 'root'], array_keys($unitOfWork->getEntityChangeSet($entity))) !== [] || $unitOfWork->isScheduledForDelete($entity);

                continue;
            }
            if (!$entity instanceof TierPriceRuleInterface) {
                continue;
            }
            $changeSet = $unitOfWork->getEntityChangeSet($entity);
            if (null !== $entity->getTaxon() || array_key_exists('taxon', $changeSet)) {
                $this->all = true;
            }
            $this->remember($entity->getProduct());
            $this->remember($entity->getVariant()?->getProduct());
            if (is_array($changeSet['product'] ?? null)) {
                $this->remember($changeSet['product'][0] ?? null);
            }
            $oldVariant = is_array($changeSet['variant'] ?? null) ? ($changeSet['variant'][0] ?? null) : null;
            if ($oldVariant instanceof ProductVariantInterface) {
                $this->remember($oldVariant->getProduct());
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->all) {
            $this->all = false;
            $this->productIds = [];
            $this->ruleProvider->invalidateAll();

            return;
        }
        if ([] === $this->productIds) {
            return;
        }
        $productIds = array_keys($this->productIds);
        $this->productIds = [];
        $this->ruleProvider->invalidateProducts(...$productIds);
    }

    private function remember(mixed $product): void
    {
        if ($product instanceof ProductInterface && is_int($product->getId())) {
            $this->productIds[$product->getId()] = true;
        }
    }
}
