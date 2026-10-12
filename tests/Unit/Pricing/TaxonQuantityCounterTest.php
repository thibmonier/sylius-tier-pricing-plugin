<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Taxon;
use Tmonier\SyliusTierPricingPlugin\Pricing\TaxonQuantityCounter;

final class TaxonQuantityCounterTest extends TestCase
{
    public function testEveryTaxonAndAncestorOfEveryTaxonOfTheProductCountsOnce(): void
    {
        $tools = $this->taxon(1);
        $drills = $this->taxon(2, $tools);
        $cordless = $this->taxon(3, $drills);
        $brand = $this->taxon(4);
        $drill = $this->product(10, $cordless, $drills, $brand);
        $saw = $this->product(11, $tools);

        $quantities = (new TaxonQuantityCounter())->count([$this->item($drill, 3), $this->item($drill, 2), $this->item($saw, 4)]);
        ksort($quantities);

        self::assertSame([1 => 9, 2 => 5, 3 => 5, 4 => 5], $quantities);
    }

    private function taxon(int $id, ?Taxon $parent = null): Taxon
    {
        $taxon = new Taxon();
        (new \ReflectionProperty(Taxon::class, 'id'))->setValue($taxon, $id);
        $parent?->addChild($taxon);

        return $taxon;
    }

    private function product(int $id, Taxon ...$taxons): Product
    {
        $product = new Product();
        (new \ReflectionProperty(Product::class, 'id'))->setValue($product, $id);
        foreach ($taxons as $taxon) {
            $productTaxon = new ProductTaxon();
            $productTaxon->setTaxon($taxon);
            $product->addProductTaxon($productTaxon);
        }

        return $product;
    }

    private function item(Product $product, int $quantity): OrderItem
    {
        $variant = new ProductVariant();
        $product->addVariant($variant);
        $item = new OrderItem();
        $item->setVariant($variant);
        (new \ReflectionProperty(\Sylius\Component\Order\Model\OrderItem::class, 'quantity'))->setValue($item, $quantity);

        return $item;
    }
}
