<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Model\ProductVariantInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleProviderInterface;

/**
 * Invalidates the cached rules of the products whose rules are created, changed (including a
 * move to another product) or deleted, once the flush succeeded.
 */
final class TierPriceCacheInvalidator
{
    /** @var array<int, true> */
    private array $productIds = [];

    public function __construct(private readonly TierPriceRuleProviderInterface $ruleProvider)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        $entities = [...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()];
        foreach ($entities as $entity) {
            if (!$entity instanceof TierPriceRuleInterface) {
                continue;
            }
            $this->remember($entity->getProduct());
            $this->remember($entity->getVariant()?->getProduct());
            $changeSet = $unitOfWork->getEntityChangeSet($entity);
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
