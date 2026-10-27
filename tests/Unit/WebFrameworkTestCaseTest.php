<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use PHPUnit\Framework\TestCase;

final class WebFrameworkTestCaseTest extends TestCase
{
    public function testClientSideStatsAreDisabledByDefault()
    {
        self::assertSame('false', WebFrameworkTestCaseEnvProbe::envs()['DD_TRACE_STATS_COMPUTATION_ENABLED']);
    }
}

final class WebFrameworkTestCaseEnvProbe extends WebFrameworkTestCase
{
    public static function envs()
    {
        return parent::getEnvs();
    }
}
