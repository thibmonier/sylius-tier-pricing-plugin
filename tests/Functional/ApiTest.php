<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Functional;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Repository\TierPriceRuleRepositoryInterface;

final class ApiTest extends WebTestCase
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

    public function testTheShopReadsTheTiersOfAVariant(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000], $this->catalog()->taxCategory($channel, 0.2, false));
        $variant = $this->catalog()->variant($product);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1000, null, TierPriceRuleInterface::BASIS_PRODUCT);

        $this->client->request('GET', sprintf('/api/v2/shop/product-variants/%s/tier-prices', (string) $variant->getCode()), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame($variant->getCode(), $data['code']);
        self::assertTrue($data['taxIncluded']);
        self::assertSame([
            ['minQuantity' => 1, 'unitPrice' => 1200, 'mixAndMatch' => false],
            ['minQuantity' => 10, 'unitPrice' => 1080, 'mixAndMatch' => true],
        ], $data['tiers']);

        $this->client->request('GET', '/api/v2/shop/product-variants/unknown-variant/tier-prices', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdministratorsManageTheRules(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $headers = $this->adminHeaders();

        $this->client->request('POST', '/api/v2/admin/tier-price-rules', server: $headers, content: (string) json_encode([
            'channel' => sprintf('/api/v2/admin/channels/%s', (string) $channel->getCode()),
            'scope' => 'product',
            'product' => sprintf('/api/v2/admin/products/%s', (string) $product->getCode()),
            'quantityBasis' => 'line',
            'minQuantity' => 10,
            'type' => 'percentage_off',
            'value' => 750,
            'enabled' => true,
        ]));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($created);
        self::assertSame(750, $created['value']);
        self::assertIsInt($created['id']);

        // Same threshold again: the application uniqueness check applies to the API too.
        $this->client->request('POST', '/api/v2/admin/tier-price-rules', server: $headers, content: (string) json_encode([
            'channel' => sprintf('/api/v2/admin/channels/%s', (string) $channel->getCode()),
            'scope' => 'product',
            'product' => sprintf('/api/v2/admin/products/%s', (string) $product->getCode()),
            'minQuantity' => 10,
            'type' => 'percentage_off',
            'value' => 500,
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('PUT', sprintf('/api/v2/admin/tier-price-rules/%d', $created['id']), server: $headers, content: (string) json_encode(['value' => 900]));
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $rule = $this->repository()->find($created['id']);
        self::assertInstanceOf(TierPriceRuleInterface::class, $rule);
        self::assertSame(900, $rule->getValue());

        $this->client->request('GET', '/api/v2/admin/tier-price-rules', server: $headers);
        self::assertResponseIsSuccessful();

        $this->client->request('DELETE', sprintf('/api/v2/admin/tier-price-rules/%d', $created['id']), server: $headers);
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->repository()->find($created['id']));
    }

    public function testTheAdminApiNeedsAnAdministrator(): void
    {
        $this->client->request('GET', '/api/v2/admin/tier-price-rules', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $admin->addRole('ROLE_API_ACCESS');
        $this->entityManager()->flush();
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($admin),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function repository(): TierPriceRuleRepositoryInterface
    {
        /** @var TierPriceRuleRepositoryInterface $repository */
        $repository = self::getContainer()->get('tmonier_tier_pricing.repository.tier_price_rule');

        return $repository;
    }
}
