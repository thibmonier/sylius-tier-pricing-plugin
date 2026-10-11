<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Unit\OrderProcessing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceCalculatorInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceQuote;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleData;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceTable;

/** Gives `price` from `minQuantity` units of the product, and records its calls. */
final class RecordingTierPriceCalculator implements TierPriceCalculatorInterface
{
    /** @var list<array{int, int, int|null}> */
    public array $calls = [];

    public function __construct(private readonly int $minQuantity, private readonly int $price)
    {
    }

    public function quote(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity, int $productQuantity, ?int $currentPrice = null): ?TierPriceQuote
    {
        $this->calls[] = [$lineQuantity, $productQuantity, $currentPrice];
        if ($productQuantity < $this->minQuantity) {
            return null;
        }

        return new TierPriceQuote(new TierPriceRuleData(1, null, null, TierPriceRuleInterface::BASIS_PRODUCT, $this->minQuantity, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, $this->price), $this->price, (int) $currentPrice, (int) $currentPrice);
    }

    public function table(ProductVariantInterface $variant, ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, int $lineQuantity = 0, int $productQuantity = 0): TierPriceTable
    {
        return new TierPriceTable([]);
    }
}
