<?php

namespace App\Config;

use Phar;

class DefaultPharConfig
{
    private bool $verbose;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    public function getCompressionType(): int
    {
        return Phar::NONE;
    }

    public function getSignatureAlgorithm(): int
    {
        return Phar::SHA256;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }
}
