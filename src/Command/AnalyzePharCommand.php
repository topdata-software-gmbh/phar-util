<?php

namespace PharTool\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AnalyzePharCommand extends Command
{
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
            $phar = new \Phar($pharPath);
            $metadata = $phar->getMetadata();
            
            $output->writeln("<info>PHAR Analysis for: {$pharPath}</info>");
            $output->writeln("Signature: " . $phar->getSignature()['hash_type']);
            $output->writeln("Compression: " . $this->getCompressionName($phar->getDefaultStub()));
            $output->writeln("File count: " . count($phar));
            $output->writeln("Total size: " . $this->formatBytes($this->getTotalSize($phar)));
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error analyzing PHAR: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }

    private function getCompressionName($stub): string 
    {
        if (strpos($stub, 'GBMB') !== false) {
            return 'BZ2';
        } elseif (strpos($stub, 'GZ') !== false) {
            return 'GZ';
        }
        return 'None';
    }

    private function getTotalSize(\Phar $phar): int
    {
        $size = 0;
        foreach ($phar as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
