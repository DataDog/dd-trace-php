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

    public function testFatalUserErrorsReportedAsError()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing a user fatal error', '/user-fatal'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /user-fatal')
                ->setError('fatal', 'Manually triggered user fatal error')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/user-fatal',
                    'http.status_code' => '500',
                    'integration.name' => 'web',
                    'error.stack' => __DIR__ . '/fatalError.php(4)',
                ]),
        ]);
    }

    public function testFatalCoreErrorsReportedAsError()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing a core fatal error', '/core-fatal'));
        });

        /* phpcs:disable */
        /* eslint-disable */
        $message = "Uncaught LogicException: Function 'doesnt_exist' not found (function 'doesnt_exist' not found or invalid function name) in " . __DIR__ . "/fatalError.php:6
Stack trace:
#0 " . __DIR__ . "/fatalError.php(6): spl_autoload_register('doesnt_exist')
#1 {main}
  thrown";
        /* eslint-enable */
        /* phpcs:enable */

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /core-fatal')
                ->setError('fatal', $message)
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/core-fatal',
                    'http.status_code' => '500',
                    'integration.name' => 'web',
                    'error.stack' => __DIR__ . '/fatalError.php(6)',
                ]),
        ]);
    }

    public function testExceptionNotHandledByAnyFramework()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing an exceptin not handled by the framework', '/unhandled-exception'));
        });

        /* eslint-disable */
        $message = "Uncaught Exception: Exception not hanlded by the framework! in " . __DIR__ . "/fatalError.php:8
Stack trace:
#0 {main}
  thrown";
        /* eslint-enable */

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /unhandled-exception')
                ->setError('fatal', $message)
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/unhandled-exception',
                    'http.status_code' => '500',
                    'integration.name' => 'web',
                    'error.stack' => __DIR__ . '/fatalError.php(8)',
                ]),
        ]);
    }
}
