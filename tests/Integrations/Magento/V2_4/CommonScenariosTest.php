<?php

namespace DDTrace\Tests\Integrations\Magento\V2_4;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Magento/Version_2_4/pub/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'magento_test_app',
            'DD_TRACE_PDO_ENABLED' => 'false'
        ]);
    }

    public function testScenarioGetReturnString()
    {
        $this->tracesFromWebRequestSnapshot(
            function () {
                $this->call(
                    GetSpec::create(
                        'A simple GET request returning a string',
                        '/datadog/simple/index?key=value&pwd=should_redact'
                    )
                );
            },
            ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'magento.block.cache_key']
        );
    }

    public function testScenarioGetWithView()
    {
        $this->tracesFromWebRequestSnapshot(
            function () {
                $this->call(
                    GetSpec::create(
                        'A simple GET request with a view',
                        '/datadog/simpleview/index?key=value&pwd=should_redact'
                    )
                );
            },
            ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'magento.block.cache_key']
        );
    }

    public function testScenarioGetWithException()
    {
        $this->tracesFromWebRequestSnapshot(
            function () {
                $this->call(
                    GetSpec::create(
                        'A GET request with an exception',
                        '/datadog/error/index?key=value&pwd=should_redact'
                    )->expectStatusCode(500)
                );
            },
            ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'magento.block.cache_key']
        );
    }

    public function testScenarioGetToMissingRoute()
    {
        $this->tracesFromWebRequestSnapshot(
            function () {
                $this->call(
                    GetSpec::create(
                        'A GET request to a missing route',
                        '/does_not_exist?key=value&pwd=should_redact'
                    )->expectStatusCode(404)
                );
            },
            ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'magento.block.cache_key']
        );
    }
}
