<?php

namespace PharTool\Config;

interface PharConfig
{
    public function getCompressionType(): int;
    public function getSignatureAlgorithm(): int;
    public function isVerbose(): bool;
}
