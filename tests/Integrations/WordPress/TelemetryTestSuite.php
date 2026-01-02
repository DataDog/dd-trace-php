<?php

namespace DDTrace\Tests\Integrations\WordPress;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

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

        $endpoints = $this->readEndpointsTelemetry($response);
        $endpoints = isset($endpoints[0]) ? $endpoints[0] : [];
        $this->assertCount(2, $endpoints);

        $first_endpoint = $endpoints[0];
        $second_endpoint = $endpoints[1];
        if ($first_endpoint['path'] !== '/?p=1') {
            $first_endpoint = $endpoints[1];
            $second_endpoint = $endpoints[0];
        }

        $this->assertSame('/?p=1', $first_endpoint['path']);
        $this->assertSame('GET', $first_endpoint['method']);
        $this->assertSame('http.request', $first_endpoint['operation_name']);
        $this->assertSame('GET /?p=1', $first_endpoint['resource_name']);
        $this->assertSame('/?page_id=2', $second_endpoint['path']);
        $this->assertSame('GET', $second_endpoint['method']);
        $this->assertSame('http.request', $second_endpoint['operation_name']);
        $this->assertSame('GET /?page_id=2', $second_endpoint['resource_name']);
    }
}
