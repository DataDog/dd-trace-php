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
                ->setError('fatal/uncaught exception', 'Manually triggered user fatal error')
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /core-fatal')
                ->setError('fatal/uncaught exception')
                ->withExistingTagsNames(['error.msg'])
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /unhandled-exception')
                ->setError('fatal/uncaught exception')
                ->withExistingTagsNames(['error.msg'])
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/unhandled-exception',
                    'http.status_code' => '500',
                    'integration.name' => 'web',
                    'error.stack' => __DIR__ . '/fatalError.php(8)',
                ]),
        ]);
    }

    public function testExceptionThatHasBeenCaught()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing an exceptin not handled by the framework', '/caught-exception'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /caught-exception')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/caught-exception',
                    'http.status_code' => '200',
                    'integration.name' => 'web',
                ]),
        ]);
    }
}
