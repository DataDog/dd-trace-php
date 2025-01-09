<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    protected static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_DEBUG' => 'true',
            'DD_SERVICE' => 'test_symfony_23',
        ]);
    }

    protected function ddSetUp()
    {
        $this->tracesFromWebRequest(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/app.php/simple?key=value&pwd=should_redact'
                )
            );
        });
        parent::ddSetUp();
    }

    public function testScenarioGetReturnStringApache()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if (!$isApache) {
            $this->markTestSkipped('This test is only for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/app.php/simple?key=value&pwd=should_redact'
                )
            );
        });
    }
    public function testScenarioGetReturnString()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if ($isApache) {
            $this->markTestSkipped('This test is not for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/app.php/simple?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithViewApache()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if (!$isApache) {
            $this->markTestSkipped('This test is only for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request with a view',
                    '/app.php/simple_view?key=value&pwd=should_redact'
                )
            );
        });
    }
    public function testScenarioGetWithView()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if ($isApache) {
            $this->markTestSkipped('This test is not for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request with a view',
                    '/app.php/simple_view?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithExceptionApache()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if (!$isApache) {
            $this->markTestSkipped('This test is only for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/app.php/error?key=value&pwd=should_redact'
                )->expectStatusCode(500)
            );
        });
    }
    public function testScenarioGetWithException()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if ($isApache) {
            $this->markTestSkipped('This test is not for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/app.php/error?key=value&pwd=should_redact'
                )->expectStatusCode(500)
            );
        });
    }

    public function testScenarioGetToMissingRouteApache()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if (!$isApache) {
            $this->markTestSkipped('This test is only for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request to a missing route',
                    '/app.php/does_not_exist?key=value&pwd=should_redact'
                )->expectStatusCode(404)
            );
        });
    }
    public function testScenarioGetToMissingRoute()
    {
        $isApache = \getenv('DD_TRACE_TEST_SAPI') == 'apache2handler';
        if ($isApache) {
            $this->markTestSkipped('This test is not for apache2handler');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request to a missing route',
                    '/app.php/does_not_exist?key=value&pwd=should_redact'
                )->expectStatusCode(404)
            );
        });
    }
}
