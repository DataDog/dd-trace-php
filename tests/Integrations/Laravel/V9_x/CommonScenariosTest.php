<?php

namespace DDTrace\Tests\Integrations\Laravel\V9_x;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_9_x/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
            'DD_SERVICE' => 'my_service'
        ]);
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

    public function testScenarioGetWithException()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/error?key=value&pwd=should_redact'
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
                    '/does_not_exist?key=value&pwd=should_redact'
                )->expectStatusCode(404)
            );
        });
    }
}
