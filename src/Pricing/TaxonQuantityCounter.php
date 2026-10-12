<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;

/**
 * Quantities counted by the taxon rules with the `taxon` basis: for each taxon, the quantity of
 * the lines whose product is classified in it or in one of its children (through any of its
 * taxons, a line counted once per taxon).
 */
final class TaxonQuantityCounter
{
    /**
     * @param iterable<OrderItemInterface> $items
     *
     * @return array<int, int> quantity by taxon id
     */
    public function count(iterable $items): array
    {
        $quantities = [];
        $ancestries = [];
        foreach ($items as $item) {
            $product = $item->getVariant()?->getProduct();
            if (!$product instanceof ProductInterface || !is_int($product->getId())) {
                continue;
            }
            $ancestries[$product->getId()] ??= $this->taxonIds($product);
            foreach ($ancestries[$product->getId()] as $taxonId) {
                $quantities[$taxonId] = ($quantities[$taxonId] ?? 0) + $item->getQuantity();
            }
        }

        return $quantities;
    }

    /**
     * The taxons of the product and all their ancestors.
     *
     * @return list<int>
     */
    public function taxonIds(ProductInterface $product): array
    {
        $ids = [];
        foreach ($product->getTaxons() as $taxon) {
            for ($current = $taxon; $current instanceof TaxonInterface; $current = $current->getParent()) {
                $id = $current->getId();
                if (!is_int($id) || isset($ids[$id])) {
                    break;
                }
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }
}
