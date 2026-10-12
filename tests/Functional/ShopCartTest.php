<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final class ShopCartTest extends WebTestCase
{
    use FunctionalTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testTheCartShowsTheTierAppliedWithoutStrikingThePriceThrough(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $variant = $this->catalog()->variant($product);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $product, 20, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 2000);
        $customer = $this->loginShopCustomer();
        $this->catalog()->cart($channel, [[$variant, 12]], $customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/cart/'));

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-test-cart-item-product-row="' . $product->getName() . '"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('9.00', $row->filter('[data-test-cart-item-unit-price]')->text());
        self::assertCount(0, $row->filter('[data-test-cart-item-unit-regular-price]'), 'a tier price is not a reduction');
        self::assertStringContainsString('Volume price from 10 pieces', $row->filter('[data-test-tmonier-tier-applied]')->text());
        self::assertStringContainsString('Add 8 more to pay', $row->filter('[data-test-tmonier-tier-nudge]')->text());
        self::assertStringContainsString('8.00', $row->filter('[data-test-tmonier-tier-nudge]')->text());
    }

    public function testACartLineWithoutTierKeepsTheCoreDisplay(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $customer = $this->loginShopCustomer();
        $this->catalog()->cart($channel, [[$this->catalog()->variant($product), 4]], $customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/cart/'));

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-test-cart-item-product-row="' . $product->getName() . '"]');
        self::assertStringContainsString('10.00', $row->filter('[data-test-cart-item-unit-price]')->text());
        self::assertCount(0, $row->filter('[data-test-tmonier-tier-applied]'));
        self::assertStringContainsString('Add 6 more to pay', $row->filter('[data-test-tmonier-tier-nudge]')->text());
    }

    public function testTheNudgeUsesTheTaxConventionOfTheUnitPriceNextToIt(): void
    {
        $channel = $this->catalog()->channel();
        // Consumer channel, prices stored without tax: the cell (9.00) and the nudge (8.00) are
        // both without tax and labelled so; the next tier never looks more expensive.
        $product = $this->catalog()->product($channel, [1000], $this->catalog()->taxCategory($channel, 0.2, false));
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $product, 20, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 2000);
        $customer = $this->loginShopCustomer();
        $this->catalog()->cart($channel, [[$this->catalog()->variant($product), 12]], $customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/cart/'));

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-test-cart-item-product-row="' . $product->getName() . '"]');
        self::assertStringContainsString('9.00', $row->filter('[data-test-cart-item-unit-price]')->text());
        $nudge = $row->filter('[data-test-tmonier-tier-nudge]')->text();
        self::assertStringContainsString('8.00 excl. tax', $nudge);
        self::assertStringNotContainsString('9.60', $nudge);
    }

    public function testAnExemptLineGetsNoNudgeAndDoesNotCount(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        $exempt = $this->catalog()->variant($product, 1);
        $exempt->setCode($exempt->getCode() . '_exempt');
        $this->entityManager()->flush();
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, null, TierPriceRuleInterface::BASIS_PRODUCT);
        $customer = $this->loginShopCustomer();
        $this->catalog()->cart($channel, [[$this->catalog()->variant($product, 0), 4], [$exempt, 8]], $customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/cart/'));

        self::assertResponseIsSuccessful();
        $nudges = $crawler->filter('[data-test-tmonier-tier-nudge]');
        // Only the non-exempt line, counting its own 4 pieces (not the 8 exempt ones): 6 more.
        self::assertCount(1, $nudges);
        self::assertStringContainsString('Add 6 more to pay', $nudges->text());
        self::assertCount(0, $crawler->filter('[data-test-tmonier-tier-applied]'));
    }

    public function testTheSignedInCustomerGetsItsGroupPrices(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $group = $this->catalog()->customerGroup();
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 3000, $group);
        $customer = $this->loginShopCustomer();
        $customer->setGroup($group);
        $this->catalog()->cart($channel, [[$this->catalog()->variant($product), 10]], $customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/cart/'));

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-test-cart-item-product-row="' . $product->getName() . '"]');
        self::assertStringContainsString('7.00', $row->filter('[data-test-cart-item-unit-price]')->text());
    }
}
