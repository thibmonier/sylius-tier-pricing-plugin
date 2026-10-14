<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Factory;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final readonly class TierPriceRuleFactory implements TierPriceRuleFactoryInterface
{
    /**
     * @param FactoryInterface<TierPriceRuleInterface> $decoratedFactory
     * @param ProductRepositoryInterface<ProductInterface> $productRepository
     */
    public function __construct(
        private FactoryInterface $decoratedFactory,
        private ProductRepositoryInterface $productRepository,
    ) {
    }

    public function createNew(): TierPriceRuleInterface
    {
        $rule = $this->decoratedFactory->createNew();
        $rule->setEnabled(true);

        return $rule;
    }

    public function createForProduct(mixed $productId): TierPriceRuleInterface
    {
        $rule = $this->createNew();
        if (is_numeric($productId)) {
            $product = $this->productRepository->find((int) $productId);
            if ($product instanceof ProductInterface) {
                $rule->setScope(TierPriceRuleInterface::SCOPE_PRODUCT);
                $rule->setProduct($product);
                $channel = $product->getChannels()->first();
                if ($channel instanceof \Sylius\Component\Core\Model\ChannelInterface) {
                    $rule->setChannel($channel);
                }
            }
        }

        return $rule;
    }
}
