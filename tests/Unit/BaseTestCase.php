<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Log\Logger;
use DDTrace\Util\Versions;
use PHPUnit\Framework;


abstract class BaseTestCase extends Framework\TestCase
{
    protected function tearDown()
    {
        \Mockery::close();
        Logger::reset();
        parent::tearDown();
    }

    protected function matchesPhpVersion($version)
    {
        return Versions::phpVersionMatches($version);
    }
}
