<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class NonExecutingEndpointsTracingTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    public function testStatusPageDoesNotGenerateTraces()
    {
        if (\getenv('DD_TRACE_TEST_SAPI') != 'fpm-fcgi') {
            $this->markTestSkipped('Only run under fpm-fcgi SAPI');
        }

        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('No spans from status page', '/status'));
        });

        self::assertEmpty($traces);
    }
}
