<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tmonier\SyliusTierPricingPlugin\Csv\TierPriceRuleCsvExporter;

#[AsCommand(name: 'tmonier:tier-pricing:export', description: 'Exports the tier price rules as CSV (same format as the import).')]
final class ExportTierPriceRulesCommand extends Command
{
    public function __construct(private readonly TierPriceRuleCsvExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'CSV file; the standard output when omitted')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Only the rules of this channel')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $stream = is_string($file) ? @fopen($file, 'w') : fopen('php://output', 'w');
        if (!is_resource($stream)) {
            $output->writeln(sprintf('<error>Cannot write "%s".</error>', is_string($file) ? $file : 'php://output'));

            return self::FAILURE;
        }

        $channel = $input->getOption('channel');
        $count = $this->exporter->export($stream, is_string($channel) ? $channel : null);
        fclose($stream);
        if (is_string($file)) {
            $output->writeln(sprintf('%d rule(s) exported to %s.', $count, $file));
        }

        return self::SUCCESS;
    }
}
