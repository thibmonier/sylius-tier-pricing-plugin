<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Bundle\ApiBundle\SectionResolver\ShopApiSection;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Sylius\Bundle\ShopBundle\SectionResolver\ShopSection;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;

/**
 * Customer group whose rules apply to an order.
 *
 * At a guest checkout (shop or shop API), Sylius attaches an existing customer (without shop
 * account, common for B2B customers created in the admin) to the order from the e-mail typed by
 * the visitor: group prices would go to anyone knowing that e-mail. So in a shop request, the
 * group only applies when the order's customer is the signed-in shop customer. Everywhere else
 * (admin, admin API, console, message consumers: orders created on behalf of a customer, quote
 * conversions, imports) the group of the order's customer applies.
 */
final readonly class OrderCustomerGroupResolver
{
    public function __construct(
        private ?SectionProviderInterface $sectionProvider = null,
        private ?CustomerContextInterface $customerContext = null,
        private bool $groupPricesForGuests = false,
    ) {
    }

    public function resolve(OrderInterface $order): ?CustomerGroupInterface
    {
        $customer = $order->getCustomer();
        if (!$customer instanceof \Sylius\Component\Customer\Model\CustomerInterface || $this->groupPricesForGuests || !$this->isShopRequest()) {
            return $customer?->getGroup();
        }

        $signedIn = $this->customerContext?->getCustomer();

        return $signedIn instanceof \Sylius\Component\Customer\Model\CustomerInterface && $signedIn === $customer ? $customer->getGroup() : null;
    }

    private function isShopRequest(): bool
    {
        $section = $this->sectionProvider?->getSection();

        return $section instanceof ShopSection || $section instanceof ShopApiSection;
    }
}
