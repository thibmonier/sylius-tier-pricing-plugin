<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Symfony\Contracts\Service\ResetInterface;

final class PriceDisplayResolver implements PriceDisplayResolverInterface, ResetInterface
{
    /** @var array<string, string|null> tax convention per tax category and zone (one query per pair and request) */
    private array $conventions = [];

    /**
     * @param list<string> $businessChannelCodes
     * @param array{b2c: bool, b2b: bool} $percentageColumn
     */
    public function __construct(
        private readonly TaxRateResolverInterface $taxRateResolver,
        private readonly array $businessChannelCodes,
        private readonly array $percentageColumn,
    ) {
    }

    public function isBusinessChannel(ChannelInterface $channel): bool
    {
        return in_array($channel->getCode(), $this->businessChannelCodes, true);
    }

    public function showsPercentage(ChannelInterface $channel): bool
    {
        return $this->percentageColumn[$this->isBusinessChannel($channel) ? 'b2b' : 'b2c'];
    }

    public function taxConvention(ProductVariantInterface $variant, ChannelInterface $channel): ?string
    {
        $zone = $channel->getDefaultTaxZone();
        $category = $variant->getTaxCategory();
        if (!$zone instanceof \Sylius\Component\Addressing\Model\ZoneInterface || !$category instanceof \Sylius\Component\Taxation\Model\TaxCategoryInterface) {
            return null;
        }
        $key = sprintf('%s|%s', spl_object_id($category), spl_object_id($zone));
        if (!array_key_exists($key, $this->conventions)) {
            $rate = $this->taxRateResolver->resolve($variant, ['zone' => $zone]);
            $this->conventions[$key] = $rate instanceof \Sylius\Component\Taxation\Model\TaxRateInterface ? $rate->isIncludedInPrice() ? self::TAX_INCLUDED : self::TAX_EXCLUDED : (null);
        }

        return $this->conventions[$key];
    }

    public function reset(): void
    {
        $this->conventions = [];
    }
}
