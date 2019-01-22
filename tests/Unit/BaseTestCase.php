<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Util\Environment;
use PHPUnit\Framework;


abstract class BaseTestCase extends Framework\TestCase
{
    protected function tearDown()
    {
        \Mockery::close();
        parent::tearDown();
    }

    protected function matchesPhpVersion($version)
    {
        return Environment::matchesPhpVersion($version);
    }
}
