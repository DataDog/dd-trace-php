<?php

namespace DDTrace\Tests\Integrations\WordPress;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;

class TelemetryTestSuite extends WebFrameworkTestCase
{  
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
            'DD_TELEMETRY_HEARTBEAT_INTERVAL' => 10,
        ]);
    }

    protected function readEndpointsTelemetry($response)
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

    protected function expectedEndpoints() {
        return [
            [
                "method" => "GET",
                "operation_name" => "http.request",
                "path" => "/?page_id=2",
                "resource_name" => "GET /?page_id=2",
            ],
            [
                "method" => "GET",
                "operation_name" => "http.request",
                "path" => "/?p=1",
                "resource_name" => "GET /?p=1",
            ],
            [
                "method" => "GET",
                "operation_name" => "http.request",
                "path" => "/?p=5",
                "resource_name" => "GET /?p=5",
            ]
        ];
    }

    public function testAppEndpointsAreSent()
    {
        $this->call(
            GetSpec::create(
                'A simple GET request returning a string',
                '/simple?key=value&pwd=should_redact'
            )
        );

        $found_app_endpoints = false;
        $until = function ($request) use (&$found_app_endpoints) {
            if (strpos($request["body"] ?? "", "app-endpoints") !== false) {
                $found_app_endpoints = true;
            }
            return $found_app_endpoints;
        };
        $response = $this->retrieveDumpedData($until);

        //Let's wait for the telemetry data to be sent to the agent
        sleep(3);
        $endpoints = $this->readEndpointsTelemetry($response);
        $endpoints = isset($endpoints[0]) ? $endpoints[0] : [];

        $expected_endpoints = $this->expectedEndpoints();

        $this->assertCount(count($expected_endpoints), $endpoints);

        foreach ($expected_endpoints as $expected_endpoint) {
            $this->assertContains($expected_endpoint, $endpoints);
        }
    }
}
