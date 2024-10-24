#!/usr/bin/env php
<?php

class PharTool
{
    private $operation;
    private $sourcePath;
    private $targetPath;
    private $originalInfo;

    public function __construct($args)
    {
        if (php_sapi_name() !== 'cli') {
            die('This script can only be run from command line');
        }

        if (count($args) === 1 || in_array('-h', $args) || in_array('--help', $args)) {
            $this->usage();
            exit(0);
        }

        if (count($args) !== 4) {
            $this->usage();
            exit(1);
        }

        $this->operation = $args[1];
        $this->sourcePath = $args[2];
        $this->targetPath = $args[3];

        if (!in_array($this->operation, ['extract', 'repack'])) {
            echo "Error: Invalid operation. Use 'extract' or 'repack'\n";
            $this->usage();
            exit(1);
        }

        if ($this->operation === 'repack' && ini_get('phar.readonly')) {
            die("Error: phar.readonly is enabled. Run with:\nphp -d phar.readonly=0 " . implode(' ', $args) . "\n");
        }
    }

    public function usage()
    {
        echo <<<EOT
PHAR Tool - Extract and Repack PHAR archives

Usage:
    php phar-tool.php <operation> <source> <target>

Operations:
    extract     Extract PHAR file to directory
    repack      Create PHAR file from directory

Arguments:
    source      Source PHAR file (for extract) or directory (for repack)
    target      Target directory (for extract) or PHAR file (for repack)

Options:
    -h, --help  Show this help message

Examples:
    Extract PHAR:
    php phar-tool.php extract shopware-installer.phar.php extracted_files/

    Repack PHAR:
    php -d phar.readonly=0 phar-tool.php repack extracted_files/ new-shopware-installer.phar.php

Note:
    For repacking, you need to disable phar.readonly. Use one of these methods:
    1. php -d phar.readonly=0 phar-tool.php ...
    2. Create local php.ini with "phar.readonly = 0" and run: php -c . phar-tool.php ...
    3. Set phar.readonly = 0 in your system's php.ini

EOT;
    }

    private function analyzeExistingPhar($pharPath)
    {
        try {
            $phar = new Phar($pharPath);
            $metadata = $phar->getMetadata();

            echo "\nAnalyzing original PHAR:\n";
            echo "Size: " . $this->formatBytes(filesize($pharPath)) . "\n";
            echo "Files: " . count($phar) . "\n";
            echo "Compression: ";

            $compressionInfo = [];
            foreach ($phar as $file) {
                if ($file->isCompressed()) {
                    if ($file->isCompressed(Phar::BZ2)) {
                        $compressionInfo['BZ2'] = ($compressionInfo['BZ2'] ?? 0) + 1;
                    }
                    if ($file->isCompressed(Phar::GZ)) {
                        $compressionInfo['GZ'] = ($compressionInfo['GZ'] ?? 0) + 1;
                    }
                } else {
                    $compressionInfo['None'] = ($compressionInfo['None'] ?? 0) + 1;
                }
            }

            foreach ($compressionInfo as $type => $count) {
                echo "$type: $count files, ";
            }
            echo "\n";

            return [
                'metadata'    => $metadata,
                'compression' => $compressionInfo
            ];
        } catch (Exception $e) {
            echo "Warning: Could not analyze original PHAR: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }

    private function extractPhar()
    {
        if (!file_exists($this->sourcePath)) {
            die("Error: Source PHAR file does not exist: {$this->sourcePath}\n");
        }

        // Store original PHAR information
        $this->originalInfo = $this->analyzeExistingPhar($this->sourcePath);

        // Ensure the extraction directory exists
        if (!is_dir($this->targetPath)) {
            mkdir($this->targetPath, 0777, true);
        }

        $phar = new Phar($this->sourcePath);
        $phar->extractTo($this->targetPath, null, true);

        // Save compression info for repack
        file_put_contents(
            $this->targetPath . DIRECTORY_SEPARATOR . '.pharinfo',
            json_encode($this->originalInfo)
        );

        echo "\nSuccessfully extracted PHAR to: {$this->targetPath}\n";
    }

    private function repackPhar()
    {
        if (!is_dir($this->sourcePath)) {
            die("Error: Source directory does not exist: {$this->sourcePath}\n");
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

        $phar = new Phar($this->targetPath);
        $phar->setSignatureAlgorithm(Phar::SHA1);

        $phar->startBuffering();

        $dirIterator = new RecursiveDirectoryIterator(
            $this->sourcePath,
            RecursiveDirectoryIterator::SKIP_DOTS
        );
        $iterator = new RecursiveIteratorIterator($dirIterator);

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
                    $file->compress(Phar::BZ2);
                } elseif (isset($this->originalInfo['compression']['GZ'])) {
                    $file->compress(Phar::GZ);
                }
            }
        } else {
            // Default to GZ compression
            $phar->compressFiles(Phar::GZ);
        }

        // Set original metadata if available
        if (isset($this->originalInfo['metadata'])) {
            $phar->setMetadata($this->originalInfo['metadata']);
        }

        $phar->stopBuffering();

        echo "\nRepacking complete!\n";
        echo "Files processed: $fileCount\n";
        echo "Original size: " . (isset($this->originalInfo) ? $this->formatBytes(filesize($this->sourcePath)) : "unknown") . "\n";
        echo "New size: " . $this->formatBytes(filesize($this->targetPath)) . "\n";

        // Analyze new PHAR
        echo "\nNew PHAR analysis:\n";
        $this->analyzeExistingPhar($this->targetPath);
    }

    public function run()
    {
        try {
            if ($this->operation === 'extract') {
                $this->extractPhar();
            } else {
                $this->repackPhar();
            }
        } catch (Exception $e) {
            die("Error: " . $e->getMessage() . "\n");
        }
    }
}

// Run the tool
$tool = new PharTool($argv);
$tool->run();