<?php

namespace App\Command;

use App\Service\PharAnalyzer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AnalyzePharCommand extends Command
{
    private PharAnalyzer $pharAnalyzer;

    public function __construct(PharAnalyzer $pharAnalyzer)
    {
        parent::__construct();
        $this->pharAnalyzer = $pharAnalyzer;
    }

    protected function configure()
    {
        $this
            ->setName('phar:analyze')
            ->setDescription('Analyze a PHAR archive')
            ->addArgument(
                'phar',
                InputArgument::REQUIRED,
                'Path to the PHAR file'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pharPath = $input->getArgument('phar');

        if (!file_exists($pharPath)) {
            $output->writeln("<error>Error: PHAR file not found: {$pharPath}</error>");
            return Command::FAILURE;
        }

        try {
            $analysis = $this->pharAnalyzer->analyzePhar($pharPath);
            dump($analysis);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error analyzing PHAR: " . $e->getMessage() . "</error>");

            return Command::FAILURE;
        }
    }
}
