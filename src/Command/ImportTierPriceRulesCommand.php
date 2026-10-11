<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvFormat;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvImporter;

#[AsCommand(name: 'tmonier:tier-pricing:import', description: 'Imports tier price rules from a CSV file (all or nothing).')]
final class ImportTierPriceRulesCommand extends Command
{
    public function __construct(private readonly TierPriceRuleCsvImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'CSV file, "-" for the standard input')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'upsert (create or update) or replace (delete the existing rules first)', TierPriceRuleCsvImporter::MODE_UPSERT)
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Only this channel (replace mode deletes the rules of this channel only)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and count without saving')
            ->setHelp(sprintf("Columns: %s\n\nvalue: amount (12.50) for fixed_unit_price, percentage (7.5) for percentage_off.", implode(',', TierPriceRuleCsvFormat::HEADER)))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = $input->getOption('mode');
        if (!is_string($mode) || !in_array($mode, [TierPriceRuleCsvImporter::MODE_UPSERT, TierPriceRuleCsvImporter::MODE_REPLACE], true)) {
            $io->error('The mode must be "upsert" or "replace".');

            return self::INVALID;
        }
        $file = $input->getArgument('file');
        if (!is_string($file)) {
            return self::INVALID;
        }
        $stream = '-' === $file ? \STDIN : @fopen($file, 'r');
        if (!is_resource($stream)) {
            $io->error(sprintf('Cannot read "%s".', $file));

            return self::FAILURE;
        }

        $channel = $input->getOption('channel');
        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->importer->import($stream, $mode, $dryRun, is_string($channel) ? $channel : null);
        if ('-' !== $file) {
            fclose($stream);
        }

        foreach ($result->errors as $error) {
            $io->writeln(sprintf('<error>%s</error>', $error));
        }
        $summary = sprintf('%d created, %d updated, %d deleted', $result->created, $result->updated, $result->deleted);
        if (!$result->isSuccessful()) {
            $io->error(sprintf('%d invalid line(s): nothing imported (%s before rollback).', count($result->errors), $summary));

            return self::FAILURE;
        }
        if ($dryRun) {
            $io->success(sprintf('Dry run, nothing saved: %s.', $summary));

            return self::SUCCESS;
        }
        $io->success(sprintf('Imported: %s.', $summary));

        return self::SUCCESS;
    }
}
