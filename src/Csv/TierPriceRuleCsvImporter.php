<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Csv;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Tmonier\SyliusTierPricingPlugin\Factory\TierPriceRuleFactoryInterface;
use Tmonier\SyliusTierPricingPlugin\Pricing\TierPriceRuleProviderInterface;

/**
 * All or nothing: the whole file is imported in one transaction, rolled back on the first
 * invalid line (every error is still reported) or in dry-run mode.
 *
 * - upsert: a line updates the rule with the same channel, customer group, target, threshold and
 *   dates, or creates one;
 * - replace: deletes every rule (of the channel with `$channelCode`) first.
 *
 * The entity manager is cleared every 500 lines; channels, groups, products and variants are
 * then referenced by identifier (`getReference()`), not reloaded.
 */
final class TierPriceRuleCsvImporter
{
    public const MODE_UPSERT = 'upsert';

    public const MODE_REPLACE = 'replace';

    private const BATCH_SIZE = 500;

    /** @var array<string, array<string, int|null>> class => code => id */
    private array $ids = [];

    /** @var array<int, int> rule id => line that created or updated it in this import */
    private array $linesByRule = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TierPriceRuleFactoryInterface $factory,
        private readonly ValidatorInterface $validator,
        private readonly TierPriceRuleProviderInterface $ruleProvider,
    ) {
    }

    /** @param resource $stream */
    public function import($stream, string $mode = self::MODE_UPSERT, bool $dryRun = false, ?string $channelCode = null): TierPriceRuleImportResult
    {
        $result = new TierPriceRuleImportResult();
        $this->ids = [];
        $this->linesByRule = [];
        $header = fgetcsv($stream, escape: '');
        // Excel saves "CSV UTF-8" with a byte order mark.
        if (is_array($header) && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $columns = is_array($header) ? array_map(static fn (?string $cell): string => strtolower(trim((string) $cell)), $header) : [];
        if (TierPriceRuleCsvFormat::HEADER !== $columns && TierPriceRuleCsvFormat::HEADER_1_0 !== $columns) {
            $result->errors[] = sprintf('line 1: the header must be "%s"', implode(',', TierPriceRuleCsvFormat::HEADER));

            return $result;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            if (self::MODE_REPLACE === $mode) {
                $result->deleted = $this->deleteAll($channelCode);
            }

            $line = 1;
            $rowsInBatch = 0;
            while (false !== ($row = fgetcsv($stream, escape: ''))) {
                ++$line;
                if ([null] === $row || [] === array_filter($row, static fn (?string $cell): bool => '' !== trim((string) $cell))) {
                    continue;
                }
                $error = $this->importRow($row, $result, $channelCode, $line, $columns);
                if (null !== $error) {
                    $result->errors[] = sprintf('line %d: %s', $line, $error);

                    continue;
                }
                if (++$rowsInBatch >= self::BATCH_SIZE) {
                    $this->entityManager->clear();
                    $rowsInBatch = 0;
                }
            }

            if ($dryRun || !$result->isSuccessful()) {
                $connection->rollBack();
            } else {
                $connection->commit();
                $result->committed = true;
            }
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            $this->entityManager->clear();
            $this->ruleProvider->invalidateAll();
        }

        return $result;
    }

    /**
     * @param list<string|null> $row
     * @param list<string> $columns header of the file (1.0 or 1.1)
     */
    private function importRow(array $row, TierPriceRuleImportResult $result, ?string $onlyChannel, int $line, array $columns): ?string
    {
        if (count($row) !== count($columns)) {
            return sprintf('%d columns expected, %d found', count($columns), count($row));
        }
        /** @var array{channel: string, customer_group: string, scope: string, product: string, variant: string, quantity_basis: string, min_quantity: string, type: string, value: string, enabled: string, starts_at: string, ends_at: string, taxon: string, cumulation: string} $data */
        $data = array_combine($columns, array_map(static fn (?string $cell): string => trim((string) $cell), $row)) + ['taxon' => '', 'cumulation' => ''];

        if (null !== $onlyChannel && $data['channel'] !== $onlyChannel) {
            return sprintf('channel "%s" outside of the imported channel "%s"', $data['channel'], $onlyChannel);
        }
        $channel = $this->reference(ChannelInterface::class, $data['channel']);
        if (null === $channel) {
            return sprintf('unknown channel "%s"', $data['channel']);
        }
        $customerGroup = null;
        if ('' !== $data['customer_group']) {
            $customerGroup = $this->reference(CustomerGroupInterface::class, $data['customer_group']);
            if (null === $customerGroup) {
                return sprintf('unknown customer group "%s"', $data['customer_group']);
            }
        }
        $product = null;
        $variant = null;
        $taxon = null;
        if (TierPriceRuleInterface::SCOPE_TAXON === $data['scope']) {
            $taxon = $this->reference(TaxonInterface::class, $data['taxon']);
            if (null === $taxon) {
                return sprintf('unknown taxon "%s"', $data['taxon']);
            }
        } elseif (TierPriceRuleInterface::SCOPE_VARIANT === $data['scope']) {
            $variant = $this->reference(ProductVariantInterface::class, $data['variant']);
            if (null === $variant) {
                return sprintf('unknown variant "%s"', $data['variant']);
            }
        } elseif (TierPriceRuleInterface::SCOPE_PRODUCT === $data['scope']) {
            $product = $this->reference(ProductInterface::class, $data['product']);
            if (null === $product) {
                return sprintf('unknown product "%s"', $data['product']);
            }
        } else {
            return sprintf('unknown scope "%s" (variant, product or taxon)', $data['scope']);
        }
        if (1 !== preg_match('/^\d+$/', $data['min_quantity'])) {
            return sprintf('invalid min_quantity "%s"', $data['min_quantity']);
        }
        $value = TierPriceRuleCsvFormat::parseValue($data['value']);
        if (null === $value) {
            return sprintf('invalid value "%s"', $data['value']);
        }

        $enabled = strtolower($data['enabled']);
        if (!in_array($enabled, ['', '1', 'true', 'yes', '0', 'false', 'no'], true)) {
            return sprintf('invalid enabled "%s" (1, true, yes, 0, false, no or empty)', $data['enabled']);
        }

        try {
            // Offsets kept: converted to the application timezone of the datetime columns.
            $timezone = new \DateTimeZone(date_default_timezone_get());
            $startsAt = '' === $data['starts_at'] ? null : (new \DateTime($data['starts_at']))->setTimezone($timezone);
            $endsAt = '' === $data['ends_at'] ? null : (new \DateTime($data['ends_at']))->setTimezone($timezone);
        } catch (\Exception) {
            return 'invalid date';
        }

        $cumulation = '' === $data['cumulation'] ? TierPriceRuleInterface::CUMULATION_BEST_OF : $data['cumulation'];

        $rule = $this->findExisting($channel, $customerGroup, $data['scope'], $product, $variant, $taxon, (int) $data['min_quantity'], $startsAt, $endsAt);
        $isNew = null === $rule;
        if (null !== $rule && isset($this->linesByRule[(int) $rule->getId()])) {
            return sprintf('duplicate of line %d', $this->linesByRule[(int) $rule->getId()]);
        }
        $rule ??= $this->factory->createNew();
        $rule->setChannel($channel);
        $rule->setCustomerGroup($customerGroup);
        $rule->setScope($data['scope']);
        $rule->setProduct($product);
        $rule->setVariant($variant);
        $rule->setTaxon($taxon);
        $rule->setCumulation($cumulation);
        $rule->setQuantityBasis('' === $data['quantity_basis'] ? TierPriceRuleInterface::BASIS_LINE : $data['quantity_basis']);
        $rule->setMinQuantity((int) $data['min_quantity']);
        $rule->setType($data['type']);
        $rule->setValue($value);
        $rule->setEnabled(!in_array($enabled, ['0', 'false', 'no'], true));
        $rule->setStartsAt($startsAt);
        $rule->setEndsAt($endsAt);

        $violations = $this->validator->validate($rule, null, ['sylius']);
        if (count($violations) > 0) {
            if (!$isNew) {
                $this->entityManager->refresh($rule);
            }
            $messages = [];
            foreach ($violations as $violation) {
                /** @var ConstraintViolationInterface $violation */
                $messages[] = sprintf('%s: %s', $violation->getPropertyPath(), (string) $violation->getMessage());
            }

            return implode('; ', $messages);
        }

        // Flushed line by line: the next lines are validated against this one (uniqueness).
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
        $this->linesByRule[(int) $rule->getId()] = $line;
        $isNew ? ++$result->created : ++$result->updated;

        return null;
    }

    private function findExisting(ChannelInterface $channel, ?CustomerGroupInterface $customerGroup, string $scope, ?ProductInterface $product, ?ProductVariantInterface $variant, ?TaxonInterface $taxon, int $minQuantity, ?\DateTimeInterface $startsAt, ?\DateTimeInterface $endsAt): ?TierPriceRuleInterface
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(TierPriceRuleInterface::class, 'o')
            ->andWhere('o.channel = :channel')
            ->andWhere('o.scope = :scope')
            ->andWhere('o.minQuantity = :minQuantity')
            ->setParameter('channel', $channel)
            ->setParameter('scope', $scope)
            ->setParameter('minQuantity', $minQuantity)
            ->setMaxResults(1)
        ;
        foreach (['customerGroup' => $customerGroup, 'product' => $product, 'variant' => $variant, 'taxon' => $taxon, 'startsAt' => $startsAt, 'endsAt' => $endsAt] as $field => $value) {
            if (null === $value) {
                $queryBuilder->andWhere(sprintf('o.%s IS NULL', $field));
            } else {
                $queryBuilder->andWhere(sprintf('o.%1$s = :%1$s', $field))->setParameter($field, $value);
            }
        }

        $rule = $queryBuilder->getQuery()->getOneOrNullResult();

        return $rule instanceof TierPriceRuleInterface ? $rule : null;
    }

    private function deleteAll(?string $channelCode): int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()->delete(TierPriceRuleInterface::class, 'o');
        if (null !== $channelCode) {
            $channelId = $this->id(ChannelInterface::class, $channelCode);
            if (null === $channelId) {
                return 0;
            }
            $queryBuilder->andWhere('o.channel = :channel')->setParameter('channel', $channelId);
        }
        $deleted = $queryBuilder->getQuery()->execute();

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function reference(string $class, string $code): ?object
    {
        $id = '' === $code ? null : $this->id($class, $code);

        return null === $id ? null : $this->entityManager->getReference($class, $id);
    }

    /** @param class-string $class */
    private function id(string $class, string $code): ?int
    {
        if (!array_key_exists($code, $this->ids[$class] ?? [])) {
            $id = $this->entityManager->createQueryBuilder()
                ->select('o.id')
                ->from($class, 'o')
                ->andWhere('o.code = :code')
                ->setParameter('code', $code)
                ->getQuery()
                ->getOneOrNullResult()
            ;
            $this->ids[$class][$code] = is_array($id) && is_numeric($id['id']) ? (int) $id['id'] : null;
        }

        return $this->ids[$class][$code];
    }
}
