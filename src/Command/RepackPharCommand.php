<?php

namespace PharTool\Command;

use PharTool\Exception\PharToolException;
use PharTool\Service\PharAnalyzer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RepackPharCommand extends Command
{

    private function validatePharReadonly(OutputInterface $output): bool
    {
        if (ini_get('phar.readonly')) {
            $output->writeln("<error>Error: phar.readonly is enabled. Run with:\nphp -d phar.readonly=0 " . $_SERVER['PHP_SELF'] . "</error>");
            return false;
        }
        return true;
    }

    protected function configure(): void
    {
        $this->setName('phar:repack')
            ->setDescription('Repack a directory into a PHAR archive')
            ->addArgument('source', InputArgument::REQUIRED, 'Source directory')
            ->addArgument('target', InputArgument::REQUIRED, 'Target PHAR file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->validatePharReadonly($output)) {
            return Command::FAILURE;
        }

        $sourcePath = $input->getArgument('source');
        $targetPath = $input->getArgument('target');

        try {
            if (!is_dir($sourcePath)) {
                throw new PharToolException("Source directory does not exist: {$sourcePath}");
            }

            // Try to load original PHAR info
            $infoFile = $sourcePath . DIRECTORY_SEPARATOR . '.pharinfo';
            $originalInfo = file_exists($infoFile) 
                ? json_decode(file_get_contents($infoFile), true)
                : null;

            // Remove existing phar files
            foreach ([$targetPath, $targetPath . '.gz'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            $phar = new \Phar($targetPath);
            $phar->setSignatureAlgorithm(\Phar::SHA1);
            $phar->startBuffering();

            $dirIterator = new \RecursiveDirectoryIterator(
                $sourcePath,
                \RecursiveDirectoryIterator::SKIP_DOTS
            );
            $iterator = new \RecursiveIteratorIterator($dirIterator);

            $fileCount = 0;
            foreach ($iterator as $file) {
                if ($file->getFilename() === '.pharinfo') continue;

                $localName = str_replace(
                    $sourcePath . DIRECTORY_SEPARATOR,
                    '',
                    $file->getPathname()
                );
                $phar->addFile($file->getPathname(), $localName);
                $fileCount++;
            }

            // Apply original compression if known
            if (isset($originalInfo['compression'])) {
                foreach ($phar as $file) {
                    if (isset($originalInfo['compression']['BZ2'])) {
                        $file->compress(\Phar::BZ2);
                    } elseif (isset($originalInfo['compression']['GZ'])) {
                        $file->compress(\Phar::GZ);
                    }
                }
            } else {
                // Default to GZ compression
                $phar->compressFiles(\Phar::GZ);
            }

            // Set original metadata if available
            if (isset($originalInfo['metadata'])) {
                $phar->setMetadata($originalInfo['metadata']);
            }

            $phar->stopBuffering();

            $output->writeln("\nRepacking complete!");
            $output->writeln("Files processed: $fileCount");
            
            // Analyze new PHAR
            $analyzer = new PharAnalyzer();
            $analyzer->analyzePhar($targetPath);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
