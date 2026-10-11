<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final class ShopProductPageTest extends WebTestCase
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

    public function testTheTierTableShowsConditionalPricesWithTaxes(): void
    {
        $channel = $this->catalog()->channel();
        // Channel prices without tax (rate not included): shown with taxes in a consumer channel.
        $product = $this->catalog()->product($channel, [1000], $this->catalog()->taxCategory($channel, 0.2, false));
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $product, 25, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 800);

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('[data-test-tmonier-tier-prices]');
        self::assertCount(1, $table);
        self::assertCount(3, $table->filter('tbody tr'));
        self::assertStringContainsString('12.00', $table->filter('[data-test-tmonier-tier-row="1"]')->text());
        self::assertStringContainsString('from 10 pieces', $table->filter('[data-test-tmonier-tier-row="10"]')->text());
        self::assertStringContainsString('10.80', $table->filter('[data-test-tmonier-tier-row="10"]')->text());
        self::assertStringContainsString('9.60', $table->filter('[data-test-tmonier-tier-row="25"]')->text());
        self::assertStringContainsString('Unit prices including taxes.', $table->text());
        // Conditional prices: no "-x %" (off by default in consumer channels), nothing struck through.
        self::assertStringNotContainsString('%', $table->text());
        self::assertCount(0, $table->filter('del, .text-decoration-line-through'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-tier-nudge]'));
    }

    public function testPricesIncludingTaxesAreShownAsTheyAre(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1200], $this->catalog()->taxCategory($channel, 0.2, true));
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 1080);

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('12.00', $crawler->filter('[data-test-tmonier-tier-row="1"]')->text());
        self::assertStringContainsString('10.80', $crawler->filter('[data-test-tmonier-tier-row="10"]')->text());
    }

    public function testNoTableWithoutRuleAndNoRuleOfAnotherGroup(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000]);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, $this->catalog()->customerGroup());

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-test-tmonier-tier-prices]'));
    }

    public function testTheNextTierIsSuggestedFromTheCartContent(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, null, TierPriceRuleInterface::BASIS_PRODUCT);
        $customer = $this->loginShopCustomer();
        // 5 + 2 units of the product in the cart: 3 more for the mix and match tier.
        $this->catalog()->cart($channel, [[$this->catalog()->variant($product, 0), 5], [$this->catalog()->variant($product, 1), 2]], $customer);

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));

        self::assertResponseIsSuccessful();
        $nudge = $crawler->filter('[data-test-tmonier-tier-nudge]');
        self::assertCount(1, $nudge);
        self::assertStringContainsString('Add 3 more to pay', $nudge->text());
        self::assertStringContainsString('9.00', $nudge->text());
        self::assertStringContainsString('All variants of the product count towards the quantity.', $crawler->filter('[data-test-tmonier-tier-prices]')->text());
    }
}
