<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusTierPricingPlugin\TierPricingTestTrait;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvExporter;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvImporter;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;

final class CsvImportExportTest extends KernelTestCase
{
    use TierPricingTestTrait;

    /** Files of 1.0 are still imported. */
    private const HEADER = "channel,customer_group,scope,product,variant,quantity_basis,min_quantity,type,value,enabled,starts_at,ends_at\n";

    private const HEADER_1_1 = "channel,customer_group,scope,product,variant,quantity_basis,min_quantity,type,value,enabled,starts_at,ends_at,taxon,cumulation\n";

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

    public function testUpsertCreatesThenUpdatesTheSameRules(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel, [1000, 1000]);
        $variant = $this->catalog()->variant($product, 1);
        $group = $this->catalog()->customerGroup();
        $csv = self::HEADER
            . sprintf("%s,,product,%s,,line,10,percentage_off,7.5,1,,\n", $channel->getCode(), $product->getCode())
            . sprintf("%s,%s,product,%s,,product,10,percentage_off,12,1,,\n", $channel->getCode(), $group->getCode(), $product->getCode())
            . sprintf("%s,,variant,,%s,line,50,fixed_unit_price,6.40,0,2030-01-01T00:00:00+00:00,\n", $channel->getCode(), $variant->getCode());

        $result = $this->importer()->import($this->stream($csv));
        self::assertSame([], $result->errors);
        self::assertSame([3, 0], [$result->created, $result->updated]);

        $rules = $this->rulesOf($product);
        self::assertCount(3, $rules);
        self::assertSame(750, $this->ruleOf($rules, 10, false)->getValue());
        self::assertSame(TierPriceRuleInterface::BASIS_PRODUCT, $this->ruleOf($rules, 10, true)->getQuantityBasis());
        $variantRule = $this->ruleOf($rules, 50, false);
        self::assertSame(640, $variantRule->getValue());
        self::assertFalse($variantRule->isEnabled());
        self::assertSame($variant->getId(), $variantRule->getVariant()?->getId());

        $result = $this->importer()->import($this->stream(self::HEADER . sprintf("%s,,product,%s,,line,10,percentage_off,8,1,,\n", $channel->getCode(), $product->getCode())));
        self::assertSame([0, 1], [$result->created, $result->updated]);
        $this->entityManager()->clear();
        $values = array_map(static fn (TierPriceRuleInterface $rule): int => $rule->getValue(), $this->rulesOf($product));
        self::assertContains(800, $values);
        self::assertCount(3, $values);
    }

    public function testAnInvalidLineRollsTheWholeFileBackAndReportsEveryError(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $csv = self::HEADER
            . sprintf("%s,,product,%s,,line,10,percentage_off,5,1,,\n", $channel->getCode(), $product->getCode())
            . sprintf("%s,,product,unknown-product,,line,10,percentage_off,5,1,,\n", $channel->getCode())
            . sprintf("%s,,product,%s,,line,10,percentage_off,6,1,,\n", $channel->getCode(), $product->getCode())
            . sprintf("%s,,product,%s,,line,1,percentage_off,abc,1,,\n", $channel->getCode(), $product->getCode());

        $result = $this->importer()->import($this->stream($csv));

        self::assertFalse($result->committed);
        self::assertCount(3, $result->errors);
        self::assertStringStartsWith('line 3: unknown product', $result->errors[0]);
        self::assertSame('line 4: duplicate of line 2', $result->errors[1]);
        self::assertStringStartsWith('line 5: invalid value', $result->errors[2]);
        self::assertCount(0, $this->rulesOf($product));
    }

    public function testADuplicateInsideTheFileIsAnError(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $line = sprintf("%s,,product,%s,,line,10,percentage_off,5,1,,\n", $channel->getCode(), $product->getCode());
        $overlapping = sprintf("%s,,product,%s,,line,10,percentage_off,6,1,2030-01-01,\n", $channel->getCode(), $product->getCode());

        $result = $this->importer()->import($this->stream(self::HEADER . $line . $overlapping));

        self::assertCount(1, $result->errors);
        self::assertStringStartsWith('line 3: minQuantity', $result->errors[0]);
    }

    public function testDryRunAndReplaceThroughTheCommands(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $kept = $this->catalog()->rule($channel, $product, 5, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 300);
        $file = tempnam(sys_get_temp_dir(), 'tier');
        self::assertIsString($file);
        file_put_contents($file, self::HEADER . sprintf("%s,,product,%s,,line,20,fixed_unit_price,9.99,1,,\n", $channel->getCode(), $product->getCode()));

        try {
            $import = new CommandTester($this->application()->find('tmonier:tier-pricing:import'));
            $import->execute(['file' => $file, '--dry-run' => true, '--mode' => 'replace', '--channel' => $channel->getCode()]);
            self::assertSame(0, $import->getStatusCode(), $import->getDisplay());
            self::assertStringContainsString('Dry run', $import->getDisplay());
            self::assertCount(1, $this->rulesOf($product));

            $import->execute(['file' => $file, '--mode' => 'replace', '--channel' => $channel->getCode()]);
            self::assertSame(0, $import->getStatusCode(), $import->getDisplay());
            $this->entityManager()->clear();
            $rules = $this->rulesOf($product);
            self::assertCount(1, $rules);
            self::assertSame(20, $rules[0]->getMinQuantity());
            self::assertNotSame($kept->getId(), $rules[0]->getId());

            $export = new CommandTester($this->application()->find('tmonier:tier-pricing:export'));
            $export->execute(['file' => $file, '--channel' => $channel->getCode()]);
            self::assertSame(0, $export->getStatusCode());
            $exported = (string) file_get_contents($file);
            self::assertStringStartsWith(self::HEADER_1_1, $exported);
            self::assertStringContainsString(sprintf("%s,,product,%s,,line,20,fixed_unit_price,9.99,1,,,,best_of\n", $channel->getCode(), $product->getCode()), $exported);
        } finally {
            @unlink($file);
        }
    }

    public function testTheExportCanBeImportedBack(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $this->catalog()->rule($channel, $product, 10, TierPriceRuleInterface::TYPE_PERCENTAGE_OFF, 1250, endsAt: new \DateTime('2031-01-01 10:00:00'));
        /** @var TierPriceRuleCsvExporter $exporter */
        $exporter = self::getContainer()->get('tmonier_tier_pricing.csv.exporter');
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $exporter->export($stream);
        rewind($stream);

        $result = $this->importer()->import($stream, dryRun: true);

        self::assertSame([], $result->errors);
        self::assertSame(0, $result->created);
        self::assertGreaterThanOrEqual(1, $result->updated);
    }

    public function testIdenticalLinesAreReportedAsDuplicates(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $first = sprintf("%s,,product,%s,,line,10,percentage_off,5,1,,\n", $channel->getCode(), $product->getCode());
        $copy = sprintf("%s,,product,%s,,line,10,percentage_off,30,1,,\n", $channel->getCode(), $product->getCode());

        $result = $this->importer()->import($this->stream(self::HEADER . $first . $copy));

        self::assertSame(['line 3: duplicate of line 2'], $result->errors);
        self::assertFalse($result->committed);
        self::assertCount(0, $this->rulesOf($product));
    }

    public function testABomIsIgnoredAndEnabledValuesAreStrict(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $csv = "\xEF\xBB\xBF" . self::HEADER
            . sprintf("%s,,product,%s,,line,10,percentage_off,5,off,,\n", $channel->getCode(), $product->getCode())
            . sprintf("%s,,product,%s,,line,20,percentage_off,6,no,,\n", $channel->getCode(), $product->getCode());

        $result = $this->importer()->import($this->stream($csv));

        self::assertSame(['line 2: invalid enabled "off" (1, true, yes, 0, false, no or empty)'], $result->errors);
    }

    public function testDatesKeepTheirOffset(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $csv = self::HEADER . sprintf("%s,,product,%s,,line,10,percentage_off,5,1,2030-01-01T00:00:00+05:00,2030-02-01T00:00:00-03:00\n", $channel->getCode(), $product->getCode());

        self::assertSame([], $this->importer()->import($this->stream($csv))->errors);

        $this->entityManager()->clear();
        $rules = $this->rulesOf($product);
        self::assertCount(1, $rules);
        self::assertSame((new \DateTimeImmutable('2029-12-31T19:00:00+00:00'))->getTimestamp(), $rules[0]->getStartsAt()?->getTimestamp());
        self::assertSame((new \DateTimeImmutable('2030-02-01T03:00:00+00:00'))->getTimestamp(), $rules[0]->getEndsAt()?->getTimestamp());

        // Imported again (same instants, other offset): the same rule, updated.
        $again = self::HEADER . sprintf("%s,,product,%s,,line,10,percentage_off,6,1,2029-12-31T19:00:00+00:00,2030-02-01T03:00:00+00:00\n", $channel->getCode(), $product->getCode());
        $result = $this->importer()->import($this->stream($again));
        self::assertSame([0, 1], [$result->created, $result->updated]);
    }

    public function testATooLargeAmountIsALineError(): void
    {
        $channel = $this->catalog()->channel();
        $product = $this->catalog()->product($channel);
        $csv = self::HEADER
            . sprintf("%s,,product,%s,,line,10,fixed_unit_price,99999999.99,1,,\n", $channel->getCode(), $product->getCode())
            . sprintf("%s,,product,%s,,line,20,fixed_unit_price,99999999999.99,1,,\n", $channel->getCode(), $product->getCode());

        $result = $this->importer()->import($this->stream($csv));

        self::assertCount(2, $result->errors);
        self::assertStringStartsWith('line 2: value: Enter a unit price between 0 and 10000000.00.', $result->errors[0]);
        self::assertStringStartsWith('line 3: invalid value', $result->errors[1]);
    }

    /** @param list<TierPriceRuleInterface> $rules */
    private function ruleOf(array $rules, int $minQuantity, bool $grouped): TierPriceRuleInterface
    {
        foreach ($rules as $rule) {
            if ($rule->getMinQuantity() === $minQuantity && $grouped === (null !== $rule->getCustomerGroup())) {
                return $rule;
            }
        }

        self::fail(sprintf('No rule from %d', $minQuantity));
    }

    /** @return list<TierPriceRuleInterface> */
    private function rulesOf(\Sylius\Component\Core\Model\ProductInterface $product): array
    {
        /** @var \Tmonier\SyliusTierPricingPlugin\Repository\TierPriceRuleRepositoryInterface $repository */
        $repository = self::getContainer()->get('tmonier_tier_pricing.repository.tier_price_rule');
        $product = $this->entityManager()->find(\Sylius\Component\Core\Model\Product::class, $product->getId());
        self::assertNotNull($product);

        return $repository->findByProduct($product);
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function application(): Application
    {
        self::assertNotNull(self::$kernel);

        return new Application(self::$kernel);
    }

    private function importer(): TierPriceRuleCsvImporter
    {
        /** @var TierPriceRuleCsvImporter $importer */
        $importer = self::getContainer()->get('tmonier_tier_pricing.csv.importer');

        return $importer;
    }

    public function testTaxonRulesAndCumulationAreImportedAndExported(): void
    {
        $channel = $this->catalog()->channel();
        $tools = $this->catalog()->taxon();

        $result = $this->importer()->import($this->stream(self::HEADER_1_1 . sprintf("%s,,taxon,,,taxon,10,percentage_off,10,1,,,%s,on_top\n", $channel->getCode(), $tools->getCode())));

        self::assertSame([], $result->errors);
        self::assertSame(1, $result->created);
        $rule = $this->entityManager()->getRepository(TierPriceRuleInterface::class)->findOneBy(['taxon' => $tools->getId()]);
        self::assertInstanceOf(TierPriceRuleInterface::class, $rule);
        self::assertSame(TierPriceRuleInterface::CUMULATION_ON_TOP, $rule->getCumulation());

        $unknown = $this->importer()->import($this->stream(self::HEADER_1_1 . sprintf("%s,,taxon,,,line,10,percentage_off,10,1,,,nope,\n", $channel->getCode())));
        self::assertStringContainsString('unknown taxon "nope"', implode(' ', $unknown->errors));

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $exporter = self::getContainer()->get('tmonier_tier_pricing.csv.exporter');
        self::assertInstanceOf(\Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvExporter::class, $exporter);
        $exporter->export($stream, (string) $channel->getCode());
        rewind($stream);
        self::assertStringContainsString(sprintf(",taxon,10,percentage_off,10,1,,,%s,on_top\n", $tools->getCode()), (string) stream_get_contents($stream));
    }
}
