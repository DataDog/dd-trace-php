<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class ErrorReportingTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/fatalError.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_NO_AUTOLOADER' => 'true',
        ]);
    }

    public function testFatalErrorsReportedAsError()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing a fatal error', '/'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /')
                ->setError('fatal', 'Manually triggered fatal error')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/',
                    'http.status_code' => '500',
                    'integration.name' => 'web',
                    'error.stack' => __DIR__ . '/fatalError.php(3)',
                ]),
        ]);
    }
}
