<?php

namespace PharTool\Command;

use PharTool\Exception\PharToolException;
use PharTool\Service\PharAnalyzer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExtractPharCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('phar:extract')
            ->setDescription('Extract a PHAR archive')
            ->addArgument('source', InputArgument::REQUIRED, 'Source PHAR file')
            ->addArgument('target', InputArgument::REQUIRED, 'Target directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourcePath = $input->getArgument('source');
        $targetPath = $input->getArgument('target');

        try {
            if (!file_exists($sourcePath)) {
                throw new PharToolException("Source PHAR file does not exist: {$sourcePath}");
            }

            // Store original PHAR information
            $analyzer = new PharAnalyzer();
            $originalInfo = $analyzer->analyzePhar($sourcePath);

            // Ensure the extraction directory exists
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0777, true);
            }

            $phar = new \Phar($sourcePath);
            $phar->extractTo($targetPath, null, true);

            // Save compression info for repack
            file_put_contents(
                $targetPath . DIRECTORY_SEPARATOR . '.pharinfo',
                json_encode($originalInfo)
            );

            $output->writeln("\nSuccessfully extracted PHAR to: {$targetPath}");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
