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

    public function testTheTierTableShowsConditionalPricesInTheConventionOfTheChannelPrices(): void
    {
        $channel = $this->catalog()->channel();
        // Channel prices without tax (rate not included): shown as stored, like the Sylius price, and labelled.
        $product = $this->catalog()->product($channel, [1000], $this->catalog()->taxCategory($channel, 0.2, false));
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        $this->catalog()->rule($channel, $product, 25, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 800);

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('[data-test-tmonier-tier-prices]');
        self::assertCount(1, $table);
        self::assertCount(3, $table->filter('tbody tr'));
        self::assertStringContainsString('10.00', $table->filter('[data-test-tmonier-tier-row="1"]')->text());
        self::assertStringContainsString('from 10 pieces', $table->filter('[data-test-tmonier-tier-row="10"]')->text());
        self::assertStringContainsString('9.00', $table->filter('[data-test-tmonier-tier-row="10"]')->text());
        self::assertStringContainsString('8.00', $table->filter('[data-test-tmonier-tier-row="25"]')->text());
        self::assertSame('Unit prices excluding taxes.', trim($table->filter('[data-test-tmonier-tier-tax-label]')->text()));
        // Conditional prices: no "-x %" (off by default in consumer channels), nothing struck through.
        self::assertStringNotContainsString('%', $table->text());
        self::assertCount(0, $table->filter('del, .text-decoration-line-through'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-tier-nudge]'));
    }

    public function testPricesIncludingTaxesAreLabelledSo(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1200], $this->catalog()->taxCategory($channel, 0.2, true));
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 1080);

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('12.00', $crawler->filter('[data-test-tmonier-tier-row="1"]')->text());
        self::assertStringContainsString('10.80', $crawler->filter('[data-test-tmonier-tier-row="10"]')->text());
        self::assertSame('Unit prices including taxes.', trim($crawler->filter('[data-test-tmonier-tier-tax-label]')->text()));
    }

    public function testNoTaxLabelWhenTheConventionIsUnknown(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000], $this->catalog()->taxCategory($channel, 0.2, false));
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000);
        // Without default tax zone, taxes depend on the address: never claim "including taxes".
        $channel->setDefaultTaxZone(null);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', $this->shopUrl('/products/' . $product->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-tier-prices]'));
        self::assertCount(0, $crawler->filter('[data-test-tmonier-tier-tax-label]'));
        self::assertStringNotContainsString('taxes', $crawler->filter('[data-test-tmonier-tier-prices]')->text());
    }

    public function testABusinessChannelShowsThePercentageColumn(): void
    {
        $channel = $this->catalog()->businessChannel();
        $product = $this->catalog()->product($channel, [1000], $this->catalog()->taxCategory($channel, 0.2, false));
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1250);
        $this->catalog()->rule($channel, $product, 50, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 700);

        $crawler = $this->client->request('GET', sprintf('/%s/products/%s', (string) $channel->getDefaultLocale()?->getCode(), (string) $product->getSlug()), server: ['HTTP_HOST' => (string) $channel->getHostname()]);

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('[data-test-tmonier-tier-prices]');
        self::assertStringContainsString('Saving', $table->filter('thead')->text());
        self::assertStringContainsString('8.75', $table->filter('[data-test-tmonier-tier-row="10"]')->text());
        // Against the price of one piece, never against originalPrice.
        self::assertStringContainsString('-12.5 %', $table->filter('[data-test-tmonier-tier-row="10"]')->text());
        self::assertStringContainsString('-30 %', $table->filter('[data-test-tmonier-tier-row="50"]')->text());
        self::assertSame('Unit prices excluding taxes.', trim($table->filter('[data-test-tmonier-tier-tax-label]')->text()));
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
