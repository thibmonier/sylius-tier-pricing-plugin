<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Sylius has no "prices with / without taxes" setting per channel: a channel price includes
 * the tax or not depending on the tax rate (`includedInPrice`) of the variant tax category in
 * the zone. Tier prices are shown with taxes in consumer channels and without taxes in the
 * channels listed in `b2b_channels`.
 */
interface PriceDisplayResolverInterface
{
    public function isBusinessChannel(ChannelInterface $channel): bool;

    public function showsPercentage(ChannelInterface $channel): bool;

    /** The amount (a channel price of the variant) with taxes (B2C channel) or without (B2B channel). */
    public function displayAmount(ProductVariantInterface $variant, ChannelInterface $channel, int $amount): int;
}
