<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;

/**
 * Customer group whose rules apply to an order. At a guest checkout, Sylius attaches an
 * existing customer (without shop account) to the order from the e-mail typed by the visitor:
 * group prices would then be granted to anyone knowing the e-mail of a B2B customer. By
 * default, group rules only apply to orders of a customer signed in (`createdByGuest` false,
 * set by Sylius when the cart is assigned to the authenticated shop user).
 */
final class OrderCustomerGroupResolver
{
    public function __construct(private readonly bool $groupPricesForGuests = false)
    {
    }

    public function resolve(OrderInterface $order): ?CustomerGroupInterface
    {
        if (!$this->groupPricesForGuests && $order->isCreatedByGuest()) {
            return null;
        }

        return $order->getCustomer()?->getGroup();
    }
}
