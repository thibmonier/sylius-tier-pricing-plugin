<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $catalog = $event->getMenu()->getChild('catalog');
        if (null === $catalog) {
            return;
        }

        $catalog
            ->addChild('tmonier_tier_price_rules', ['route' => 'tmonier_tier_pricing_admin_tier_price_rule_index'])
            ->setLabel('tmonier_tier_pricing.ui.tier_price_rules')
            ->setLabelAttribute('icon', 'tabler:stairs-down')
        ;
    }
}
