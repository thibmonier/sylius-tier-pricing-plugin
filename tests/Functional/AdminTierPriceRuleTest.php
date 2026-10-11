<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Repository\TierPriceRuleRepositoryInterface;

final class AdminTierPriceRuleTest extends WebTestCase
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

    public function testTheGridListsAndFiltersTheRules(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $other = $this->catalog()->product($channel);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 750);
        $this->catalog()->rule($channel, $other, 10, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 990);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/tier-price-rules/?criteria[search][value]=%s', (string) $product->getCode()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Volume pricing', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-tier-rule-target="%s"]', (string) $product->getCode())));
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-tier-rule-target="%s"]', (string) $other->getCode())));
        self::assertStringContainsString('-7.5 %', $crawler->filter('[data-test-tmonier-tier-rule-value]')->text());
    }

    public function testTheMerchantCreatesAndEditsARule(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/tier-price-rules/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Create')->form();
        $values = $form->getPhpValues();
        $fields = $values['tmonier_tier_pricing_tier_price_rule'] ?? null;
        self::assertIsArray($fields);
        $fields['channel'] = (string) $channel->getCode();
        $fields['scope'] = 'product';
        // Autocomplete fields are filled by JavaScript in the browser.
        $fields['product'] = (string) $this->idOf($product);
        $fields['quantityBasis'] = 'line';
        $fields['minQuantity'] = '12';
        $fields['type'] = 'percentage_off';
        $fields['percentage'] = '12.5';
        $fields['enabled'] = '1';
        $this->client->request('POST', '/admin/tier-price-rules/new', ['tmonier_tier_pricing_tier_price_rule' => $fields]);
        self::assertResponseRedirects();

        $rules = $this->repository()->findByProduct($product);
        self::assertCount(1, $rules);
        self::assertSame(1250, $rules[0]->getValue());
        self::assertSame(12, $rules[0]->getMinQuantity());

        // Same rule again: refused (uniqueness with a NULL customer group).
        $this->client->request('POST', '/admin/tier-price-rules/new', ['tmonier_tier_pricing_tier_price_rule' => $fields]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A rule from 12 pieces already exists', (string) $this->client->getResponse()->getContent());

        // Edit: the percentage is shown back, a fixed price replaces it.
        $crawler = $this->client->request('GET', sprintf('/admin/tier-price-rules/%d/edit', $this->idOf($rules[0])));
        self::assertResponseIsSuccessful();
        self::assertSame('12.50', $crawler->filter('#tmonier_tier_pricing_tier_price_rule_percentage')->attr('value'));
        $form = $crawler->selectButton('Update')->form();
        $values = $form->getPhpValues();
        $fields = $values['tmonier_tier_pricing_tier_price_rule'] ?? null;
        self::assertIsArray($fields);
        $fields['product'] = (string) $this->idOf($product);
        $fields['type'] = 'fixed_unit_price';
        $fields['amount'] = '7.99';
        $values['tmonier_tier_pricing_tier_price_rule'] = $fields;
        $this->client->request('POST', sprintf('/admin/tier-price-rules/%d/edit', $this->idOf($rules[0])), $values);
        self::assertResponseRedirects();
        $this->entityManager()->clear();
        $updated = $this->repository()->find($this->idOf($rules[0]));
        self::assertInstanceOf(TierPriceRuleInterface::class, $updated);
        self::assertSame(TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, $updated->getType());
        self::assertSame(799, $updated->getValue());
    }

    public function testAVariantRuleNeedsAVariant(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $this->loginAdmin();

        $this->client->request('POST', '/admin/tier-price-rules/new', ['tmonier_tier_pricing_tier_price_rule' => [
            'channel' => (string) $channel->getCode(),
            'scope' => 'variant',
            'product' => (string) $this->idOf($product),
            'quantityBasis' => 'line',
            'minQuantity' => '1',
            'type' => 'percentage_off',
            'percentage' => '10',
            '_token' => $this->csrfToken(),
        ]]);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Choose the product or the variant of the rule.', $content);
        self::assertStringContainsString('The quantity must be between 2 and 1000000.', $content);
        self::assertCount(0, $this->repository()->findByProduct($product));
    }

    public function testTheProductFormHasAVolumePricingTab(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        $rule = $this->catalog()->rule($channel, $this->catalog()->variant($product, 1), 20, TierPriceRuleInterface::TYPE_FIXED_UNIT_PRICE, 850);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', sprintf('/admin/products/%d/edit', $this->idOf($product)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-side-navigation-tab="tmonier-tier-pricing"]'));
        $tab = $crawler->filter('[data-test-tmonier-tier-pricing-tab]');
        self::assertCount(1, $tab->filter(sprintf('[data-test-tmonier-tier-pricing-rule="%d"]', $this->idOf($rule))));
        $add = $tab->filter('[data-test-tmonier-tier-pricing-add]')->attr('href');
        self::assertSame(sprintf('/admin/tier-price-rules/new/product/%d', $this->idOf($product)), $add);

        $crawler = $this->client->request('GET', (string) $add);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('#tmonier_tier_pricing_tier_price_rule_product option[value="%d"][selected]', $this->idOf($product))));
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/tier-price-rules/new');
        $token = $crawler->filter('#tmonier_tier_pricing_tier_price_rule__token')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function repository(): TierPriceRuleRepositoryInterface
    {
        /** @var TierPriceRuleRepositoryInterface $repository */
        $repository = self::getContainer()->get('tmonier_tier_pricing.repository.tier_price_rule');

        return $repository;
    }
}
