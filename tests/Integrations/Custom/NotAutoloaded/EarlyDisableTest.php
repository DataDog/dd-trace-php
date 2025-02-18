<?php

namespace DDTrace\Tests\Integrations\Custom\NotAutoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class EarlyDisableTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Not_Autoloaded/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'custom_no_autoloaded_app',
            'DD_TRACE_NO_AUTOLOADER' => 'true',
        ]);
    }

    /**
     * @throws \Exception
     */
    public function testTracingDisabledByPerdirConfig()
    {
        $sapi = \getenv('DD_TRACE_TEST_SAPI');
        if ($sapi != 'fpm-fcgi' && $sapi != "apache2handler") {
            $this->markTestSkipped('Only run under apache and fpm-fcgi SAPI');
        }

        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('A web request to a disabled endpoint', '/per-dir-disabled/test.php'));
            $this->assertSame("Tracing enabled: 0", $response);
        });

        $this->assertSpans($traces, []);
    }
}
