<?php

namespace DDTrace\Tests\Integrations\CodeIgniter\V3_1;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CodeIgniter/Version_3_1/ddshim.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'codeigniter_test_app',
        ]);
    }

    protected static function codeIgniterCgiCheck()
    {
        return strpos(getenv('DD_TRACE_TEST_SAPI'), 'cgi') === 0;
    }

    public static function getTestedLibrary()
    {
        return 'codeigniter';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '3.1.13';
    }

    public function testScenarioGetReturnString()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/simple?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithView()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request with a view',
                    '/simple_view?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithExceptionCgi()
    {
        if (!self::codeIgniterCgiCheck() && PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Skip: Test only runs with cgi SAPIs');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/error?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetToMissingRouteCgi()
    {
        if (!self::codeIgniterCgiCheck() && PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Skip: Test only runs with cgi SAPIs');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request to a missing route',
                    '/does_not_exist?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithException()
    {
        if (self::codeIgniterCgiCheck() || PHP_VERSION_ID >= 80200) {
            $this->markTestSkipped('Skip: Test doesn\'t run with cgi SAPIs');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/error?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetToMissingRoute()
    {
        if (self::codeIgniterCgiCheck() || PHP_VERSION_ID >= 80200) {
            $this->markTestSkipped('Skip: Test doesn\'t run with cgi SAPIs');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request to a missing route',
                    '/does_not_exist?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetParameterized()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request to a route with a parameter',
                    '/parameterized/paramValue'
                )
            );
        });
    }
}
