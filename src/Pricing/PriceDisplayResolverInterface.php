<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Tier prices are shown as stored, in the same tax convention as the Sylius prices next to them
 * (product price, cart unit price): Sylius has no "prices with / without taxes" setting per
 * channel, a channel price includes the tax or not depending on the tax rate
 * (`includedInPrice`) of the variant tax category in the zone. This resolver tells which one,
 * for the label.
 */
interface PriceDisplayResolverInterface
{
    public const TAX_INCLUDED = 'included';

    public const TAX_EXCLUDED = 'excluded';

    public function isBusinessChannel(ChannelInterface $channel): bool;

    public function showsPercentage(ChannelInterface $channel): bool;

    /**
     * TAX_INCLUDED or TAX_EXCLUDED from the rate of the variant in the channel default tax
     * zone; null when unknown (no default tax zone, no rate): no label is shown then.
     */
    public function taxConvention(ProductVariantInterface $variant, ChannelInterface $channel): ?string;
}
