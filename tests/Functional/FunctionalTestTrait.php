<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Functional;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\Tmonier\SyliusTierPricingPlugin\TierPricingTestTrait;

trait FunctionalTestTrait
{
    use TierPricingTestTrait;

    private KernelBrowser $client;

    private function setUpClient(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTestTransaction();
    }

    private function loginAdmin(): AdminUserInterface
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    /** The shop account of the fixtures (shop@example.com), without customer group. */
    private function loginShopCustomer(): CustomerInterface
    {
        $customer = $this->entityManager()->getRepository(CustomerInterface::class)->findOneBy(['email' => 'shop@example.com']);
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $customer->setGroup(null);
        $this->entityManager()->flush();
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->client->loginUser($user, 'shop');

        return $customer;
    }

    private function shopUrl(string $path): string
    {
        return sprintf('/%s%s', (string) $this->catalog()->channel()->getDefaultLocale()?->getCode(), $path);
    }

    private function idOf(\Sylius\Resource\Model\ResourceInterface $resource): int
    {
        $id = $resource->getId();
        self::assertIsInt($id);

        return $id;
    }
}
