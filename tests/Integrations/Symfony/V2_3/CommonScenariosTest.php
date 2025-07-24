<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '2.3.42';
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

    public function testScenarioGetReturnString()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/app.php/simple?key=value&pwd=should_redact'
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
                    '/app.php/simple_view?key=value&pwd=should_redact'
                )
            );
        });
    }

    public function testScenarioGetWithException()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/app.php/error?key=value&pwd=should_redact'
                )->expectStatusCode(500)
            );
        });
    }

    public function testScenarioGetToMissingRoute()
    {
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
