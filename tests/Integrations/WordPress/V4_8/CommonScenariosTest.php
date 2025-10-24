<?php

namespace DDTrace\Tests\Integrations\WordPress\V4_8;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static $database = "wp48";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_4_8/index.php';
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        $pdo = new \PDO('mysql:host=mysql-integration;dbname=wp48', 'test', 'test');
        $pdo->exec(file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_4_8/wp_2019-10-01.sql'));
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'wordpress_test_app',
            'DD_TRACE_WORDPRESS_CALLBACKS' => '0',
            'DD_TRACE_MYSQLI_ENABLED' => '0',
            'DD_TRACE_AGENT_PORT' => 80,
            'DD_AGENT_HOST' => 'request-replayer',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 1,
            'DD_LOGS_INJECTION' => 'false',
        ]);
    }

    private function readEndpointsTelemetry($response)
    {
        $telemetryPayloads = [];
        foreach ($response as $request) {
            if (strpos($request["uri"], "/telemetry/") === 0) {
                $json = json_decode($request["body"], true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $innerJson) {
                    if (isset($innerJson["request_type"]) && $innerJson["request_type"] == "app-endpoints") {
                        $telemetryPayloads[] = $innerJson["payload"]["endpoints"];
                    }
                }
            }
        }
        return $telemetryPayloads;
    }

    public static function getTestedLibrary()
    {
        return 'wordpress';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '4.8.10';
    }

    public function testScenarioGetReturnString()
    {
        var_dump("Alex 1");
        $this->call(
            GetSpec::create(
                'A simple GET request returning a string',
                '/simple?key=value&pwd=should_redact'
            )
        );

        usleep(600000);
        $found_app_endpoints = false;
        $until = function ($request) use (&$found_app_endpoints) {
            if (strpos($request["body"] ?? "", "app-endpoints") !== false) {
                $found_app_endpoints = true;
            }
            return $found_app_endpoints;
        };
        $response = $this->retrieveDumpedData($until);

        var_dump("Alex 2");

        $endpoints = $this->readEndpointsTelemetry($response);
        $endpoints = isset($endpoints[0]) ? $endpoints[0] : [];
        $this->assertCount(2, $endpoints);

        $first_endpoint = $endpoints[0];
        $second_endpoint = $endpoints[1];
        if ($first_endpoint['path'] !== 'http://localhost/?p=1') {
            $first_endpoint = $endpoints[1];
            $second_endpoint = $endpoints[0];
        }

        $this->assertSame('http://localhost/?p=1', $first_endpoint['path']);
        $this->assertSame('GET', $first_endpoint['method']);
        $this->assertSame('http.request', $first_endpoint['operation_name']);
        $this->assertSame('GET http://localhost/?p=1', $first_endpoint['resource_name']);
        $this->assertSame('http://localhost/?page_id=2', $second_endpoint['path']);
        $this->assertSame('GET', $second_endpoint['method']);
        $this->assertSame('http.request', $second_endpoint['operation_name']);
        $this->assertSame('GET http://localhost/?page_id=2', $second_endpoint['resource_name']);
    }

    public function testScenarioGetWithView()
    {
        if (\getenv('PHPUNIT_COVERAGE')) {
            $this->markTestSkipped('Test is too flaky under coverage mode');
        }

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
        if (\getenv('PHPUNIT_COVERAGE')) {
            $this->markTestSkipped('Test is too flaky under coverage mode');
        }

        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/error?key=value&pwd=should_redact'
                )->expectStatusCode(200)
            );
        });
    }
}
