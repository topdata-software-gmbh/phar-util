<?php

namespace PharTool\Config;

use Phar;

class DefaultPharConfig implements PharConfig
{
    private bool $verbose;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    public function getCompressionType(): int
    {
        return Phar::GZ;
    }

    public function getSignatureAlgorithm(): int
    {
        return Phar::SHA1;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }
}
