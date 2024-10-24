<?php

namespace PharTool\Command;

use PharTool\Exception\PharToolException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PharToolCommand extends Command
{
    private string $operation;
    private string $sourcePath;
    private string $targetPath;
    private string $originalInfo;

    protected function configure(): void
    {
        $this->setName('phar-tool')
            ->setDescription('Extract and Repack PHAR archives')
            ->addArgument('operation', InputArgument::REQUIRED, 'Operation to perform (extract or repack)')
            ->addArgument('source', InputArgument::REQUIRED, 'Source PHAR file or directory')
            ->addArgument('target', InputArgument::REQUIRED, 'Target directory or PHAR file');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->operation = $input->getArgument('operation');
        $this->sourcePath = $input->getArgument('source');
        $this->targetPath = $input->getArgument('target');

        if (!in_array($this->operation, ['extract', 'repack'])) {
            $output->writeln("<error>Error: Invalid operation. Use 'extract' or 'repack'</error>");
            return Command::FAILURE;
        }

        if ($this->operation === 'repack' && ini_get('phar.readonly')) {
            $output->writeln("<error>Error: phar.readonly is enabled. Run with:\nphp -d phar.readonly=0 " . $_SERVER['PHP_SELF'] . "</error>");
            return Command::FAILURE;
        }

        try {
            if ($this->operation === 'extract') {
                $this->extractPhar($output);
            } else {
                $this->repackPhar($output);
            }
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }

    private function analyzeExistingPhar(string $pharPath, OutputInterface $output): ?array
    {
        try {
            $phar = new \Phar($pharPath);
            $metadata = $phar->getMetadata();

            $output->writeln("\nAnalyzing original PHAR:");
            $output->writeln("Size: " . $this->_formatBytes(filesize($pharPath)));
            $output->writeln("Files: " . count($phar));
            $output->write("Compression: ");

            $compressionInfo = [];
            foreach ($phar as $file) {
                if ($file->isCompressed()) {
                    if ($file->isCompressed(\Phar::BZ2)) {
                        $compressionInfo['BZ2'] = ($compressionInfo['BZ2'] ?? 0) + 1;
                    }
                    if ($file->isCompressed(\Phar::GZ)) {
                        $compressionInfo['GZ'] = ($compressionInfo['GZ'] ?? 0) + 1;
                    }
                } else {
                    $compressionInfo['None'] = ($compressionInfo['None'] ?? 0) + 1;
                }
            }

            foreach ($compressionInfo as $type => $count) {
                $output->write("$type: $count files, ");
            }
            $output->writeln("");

            return [
                'metadata'    => $metadata,
                'compression' => $compressionInfo
            ];
        } catch (\Exception $e) {
            $output->writeln("Warning: Could not analyze original PHAR: " . $e->getMessage());
            return null;
        }
    }

    private function _formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }

    private function extractPhar(OutputInterface $output): void
    {
        if (!file_exists($this->sourcePath)) {
            throw new PharToolException("Source PHAR file does not exist: {$this->sourcePath}");
        }

        // Store original PHAR information
        $this->originalInfo = $this->analyzeExistingPhar($this->sourcePath, $output);

        // Ensure the extraction directory exists
        if (!is_dir($this->targetPath)) {
            mkdir($this->targetPath, 0777, true);
        }

        $phar = new \Phar($this->sourcePath);
        $phar->extractTo($this->targetPath, null, true);

        // Save compression info for repack
        file_put_contents(
            $this->targetPath . DIRECTORY_SEPARATOR . '.pharinfo',
            json_encode($this->originalInfo)
        );

        $output->writeln("\nSuccessfully extracted PHAR to: {$this->targetPath}");
    }

    private function repackPhar(OutputInterface $output): void
    {
        if (!is_dir($this->sourcePath)) {
            throw new PharToolException("Source directory does not exist: {$this->sourcePath}");
        }

        // Try to load original PHAR info
        $infoFile = $this->sourcePath . DIRECTORY_SEPARATOR . '.pharinfo';
        if (file_exists($infoFile)) {
            $this->originalInfo = json_decode(file_get_contents($infoFile), true);
        }

        // Remove existing phar files
        foreach ([$this->targetPath, $this->targetPath . '.gz'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $phar = new \Phar($this->targetPath);
        $phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        $dirIterator = new \RecursiveDirectoryIterator(
            $this->sourcePath,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        $iterator = new \RecursiveIteratorIterator($dirIterator);

        $fileCount = 0;
        foreach ($iterator as $file) {
            if ($file->getFilename() === '.pharinfo') continue;

            $localName = str_replace(
                $this->sourcePath . DIRECTORY_SEPARATOR,
                '',
                $file->getPathname()
            );
            $phar->addFile($file->getPathname(), $localName);
            $fileCount++;
        }

        // Apply original compression if known
        if (isset($this->originalInfo['compression'])) {
            foreach ($phar as $file) {
                if (isset($this->originalInfo['compression']['BZ2'])) {
                    $file->compress(\Phar::BZ2);
                } elseif (isset($this->originalInfo['compression']['GZ'])) {
                    $file->compress(\Phar::GZ);
                }
            }
        } else {
            // Default to GZ compression
            $phar->compressFiles(\Phar::GZ);
        }

        // Set original metadata if available
        if (isset($this->originalInfo['metadata'])) {
            $phar->setMetadata($this->originalInfo['metadata']);
        }

        $phar->stopBuffering();

        $output->writeln("\nRepacking complete!");
        $output->writeln("Files processed: $fileCount");
        $output->writeln("Original size: " . (isset($this->originalInfo) ? $this->_formatBytes(filesize($this->sourcePath)) : "unknown"));
        $output->writeln("New size: " . $this->_formatBytes(filesize($this->targetPath)));

        // Analyze new PHAR
        $output->writeln("\nNew PHAR analysis:");
        $this->analyzeExistingPhar($this->targetPath, $output);
    }
}
