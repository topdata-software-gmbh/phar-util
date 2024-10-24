<?php

namespace PharTool\Tests\Config;

use PHPUnit\Framework\TestCase;
use PharTool\Config\DefaultPharConfig;
use Phar;

class DefaultPharConfigTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = new DefaultPharConfig();
        
        $this->assertEquals(Phar::NONE, $config->getCompressionType());
        $this->assertEquals(Phar::SHA256, $config->getSignatureAlgorithm());
        $this->assertFalse($config->isVerbose());
    }

    public function testVerboseConfiguration(): void
    {
        $config = new DefaultPharConfig(true);
        
        $this->assertTrue($config->isVerbose());
    }
}
