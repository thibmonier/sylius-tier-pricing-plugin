<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Integration;

use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tests\Tmonier\SyliusTierPricingPlugin\TierPricingTestTrait;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRule;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final class RuleConstraintsTest extends KernelTestCase
{
    use TierPricingTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testADuplicateRuleForEverybodyIsRejectedDespiteNullGroups(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500);

        $duplicate = $this->newRule($channel, $product, 10);
        self::assertSame(['minQuantity'], $this->violationPaths($duplicate));

        // Another threshold, another group or another product: fine.
        self::assertSame([], $this->violationPaths($this->newRule($channel, $product, 20)));
        $grouped = $this->newRule($channel, $product, 10);
        $grouped->setCustomerGroup($this->catalog()->customerGroup());
        self::assertSame([], $this->violationPaths($grouped));
        self::assertSame([], $this->violationPaths($this->newRule($channel, $this->catalog()->product($channel), 10)));
    }

    public function testRulesOverDisjointPeriodsCanShareAThreshold(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 500, startsAt: new \DateTime('2030-01-01'), endsAt: new \DateTime('2030-02-01'));

        $after = $this->newRule($channel, $product, 10);
        $after->setStartsAt(new \DateTime('2030-02-01'));
        self::assertSame([], $this->violationPaths($after));

        $overlapping = $this->newRule($channel, $product, 10);
        $overlapping->setStartsAt(new \DateTime('2030-01-15'));
        $overlapping->setEndsAt(new \DateTime('2030-03-01'));
        self::assertSame(['minQuantity'], $this->violationPaths($overlapping));

        $open = $this->newRule($channel, $product, 10);
        self::assertSame(['minQuantity'], $this->violationPaths($open));
    }

    public function testTargetValueAndDatesAreChecked(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);

        $rule = new TierPriceRule();
        $rule->setChannel($channel);
        $rule->setScope(TierPriceRuleInterface::SCOPE_VARIANT);
        $rule->setProduct($product);
        $rule->setMinQuantity(1);
        $rule->setValue(12000);
        $rule->setStartsAt(new \DateTime('2030-02-01'));
        $rule->setEndsAt(new \DateTime('2030-01-01'));

        self::assertEqualsCanonicalizing(['variant', 'product', 'value', 'endsAt', 'minQuantity'], $this->violationPaths($rule));
    }

    public function testTheDatabaseRefusesARuleWithTheTargetOfAnotherScope(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);

        $this->expectException(DbalException::class);
        $this->connection()->insert('tmonier_tier_price_rule', [
            'channel_id' => $channel->getId(),
            'product_id' => $product->getId(),
            'scope' => 'variant',
            'quantity_basis' => 'line',
            'min_quantity' => 10,
            'type' => 'percentage_off',
            'value' => 500,
            'enabled' => 1,
            'created_at' => '2026-10-11 00:00:00',
        ]);
    }

    private function newRule(\Sylius\Component\Core\Model\ChannelInterface $channel, \Sylius\Component\Core\Model\ProductInterface $product, int $minQuantity): TierPriceRuleInterface
    {
        $rule = new TierPriceRule();
        $rule->setChannel($channel);
        $rule->setProduct($product);
        $rule->setMinQuantity($minQuantity);
        $rule->setValue(500);

        return $rule;
    }

    /** @return list<string> */
    private function violationPaths(TierPriceRuleInterface $rule): array
    {
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get('validator');
        $paths = [];
        foreach ($validator->validate($rule, null, ['sylius']) as $violation) {
            self::assertInstanceOf(ConstraintViolationInterface::class, $violation);
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
