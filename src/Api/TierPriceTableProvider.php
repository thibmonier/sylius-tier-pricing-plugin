<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\PriceDisplayResolverInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface;

/**
 * @implements ProviderInterface<TierPriceTableView>
 */
final class TierPriceTableProvider implements ProviderInterface
{
    /** @param ProductVariantRepositoryInterface<ProductVariantInterface> $variantRepository */
    public function __construct(
        private readonly ProductVariantRepositoryInterface $variantRepository,
        private readonly TierPriceCalculatorInterface $calculator,
        private readonly PriceDisplayResolverInterface $displayResolver,
        private readonly ChannelContextInterface $channelContext,
        private readonly CustomerContextInterface $customerContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?TierPriceTableView
    {
        $code = $uriVariables['code'] ?? null;
        $channel = $this->channelContext->getChannel();
        if (!is_string($code) || !$channel instanceof ChannelInterface) {
            return null;
        }
        $variant = $this->variantRepository->findOneBy(['code' => $code]);
        $product = $variant?->getProduct();
        if (!$variant instanceof ProductVariantInterface || !$variant->isEnabled() || !$product instanceof ProductInterface || !$product->isEnabled() || !$product->hasChannel($channel)) {
            return null;
        }

        $table = $this->calculator->table($variant, $channel, $this->customerContext->getCustomer()?->getGroup());
        $tiers = [];
        foreach ($table->rows as $row) {
            $tiers[] = [
                'minQuantity' => $row->minQuantity,
                'unitPrice' => $this->displayResolver->displayAmount($variant, $channel, $row->unitPrice),
                'mixAndMatch' => $row->mixAndMatch,
            ];
        }

        return new TierPriceTableView($code, (string) $channel->getBaseCurrency()?->getCode(), !$this->displayResolver->isBusinessChannel($channel), $tiers);
    }
}
