<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Twig;

use Sylius\Bundle\ShopBundle\Twig\OrderItemOriginalPriceToDisplayExtension as BaseExtension;
use Sylius\Component\Core\Model\OrderItem;
use Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceOrderProcessor;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Decorates `sylius_shop.twig.extension.order_item_original_price_to_display`: a tier price is a
 * conditional price, not a price reduction, so the cart does not strike the regular price
 * through for it (CJEU C-330/23, art. 6a Price Indication Directive). Only a cart promotion
 * applied on top of the tier price is shown as a reduction, from the tier price.
 */
final class OrderItemOriginalPriceToDisplayExtension extends AbstractExtension
{
    public function __construct(private readonly BaseExtension $decorated)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sylius_order_item_original_price_to_display', $this->getOriginalPriceToDisplay(...)),
        ];
    }

    public function getOriginalPriceToDisplay(OrderItem $item): ?int
    {
        if (!TierPriceOrderProcessor::hasTierPrice($item)) {
            return $this->decorated->getOriginalPriceToDisplay($item);
        }

        return $item->getUnitPrice() > $item->getDiscountedUnitPrice() ? $item->getUnitPrice() : null;
    }
}
