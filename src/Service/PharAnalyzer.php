<?php

namespace App\Service;

use App\Exception\PharToolException;
use Phar;

class PharAnalyzer
{
    /**
     * Analyzes an existing PHAR file and returns its metadata
     */
    public function analyzePhar(string $pharPath): array
    {
        try {
            $phar = new Phar($pharPath);
            $metadata = $phar->getMetadata();
            $compressionInfo = [];
            $phar->rewind();
            foreach ($phar as $file) {
//                dump($file);
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

            return [
                'metadata'    => $metadata,
                'compression' => $compressionInfo,
                'size'        => $this->_formatBytes(filesize($pharPath)),
                'files'       => count($phar)
            ];
        } catch (\Exception $e) {
            throw new PharToolException("Could not analyze PHAR: " . $e->getMessage());
        }
    }

    /**
     * Formats bytes into human readable format
     */
    private function _formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}
