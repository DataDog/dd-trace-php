<?php

namespace DDTrace\Tests\Integrations\Slim;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

abstract class TelemetryTestSuite extends WebFrameworkTestCase
{
    public static function getTestedLibrary()
    {
        return 'slim/slim';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'slim_test_app',
            'DD_TRACE_AGENT_PORT' => 80,
            'DD_AGENT_HOST' => 'request-replayer',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 1,
            'DD_TELEMETRY_HEARTBEAT_INTERVAL' => 10,
        ]);
    }

    /**
     * Routes the fixture is expected to publish as app-endpoints, as
     * {@code [path, method]} pairs. Subclasses override to pin the full set
     * for their Slim version.
     *
     * @return array<array{0:string,1:string}>
     */
    protected function expectedEndpoints()
    {
        return [];
    }

    private function readTelemetryPayloads($response)
    {
        $telemetryPayloads = [];
        foreach ($response as $request) {
            if (strpos($request["uri"], "/telemetry/") === 0) {
                $json = json_decode($request["body"], true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $innerJson) {
                    if (isset($json["application"])) {
                        $innerJson["application"] = $json["application"];
                    }
                    $telemetryPayloads[] = $innerJson;
                }
            }
        }

        return array_values($telemetryPayloads);
    }

    public function testAppEndpointsAreSent()
    {
        $this->resetRequestDumper();

        $this->call(GetSpec::create("telemetry", "/telemetry"));

        // Give the telemetry layer a moment to flush the batch.
        sleep(3);

        $response = $this->retrieveDumpedData($this->untilTelemetryRequest("app-endpoints"), true);
        $payloads = $this->readTelemetryPayloads($response);
        $endpointsPayloads = array_values(array_filter($payloads, function ($p) {
            return $p["request_type"] == "app-endpoints";
        }));

        // The are_endpoints_collected() gate must prevent duplicate publishes.
        $this->assertCount(1, $endpointsPayloads);

        $endpointsPayload = $endpointsPayloads[0];
        $endpoints = $endpointsPayload["payload"]["endpoints"];
        $this->assertGreaterThan(0, count($endpoints));
        foreach ($endpoints as $endpoint) {
            $this->assertNotEmpty($endpoint["path"]);
            $this->assertNotEmpty($endpoint["method"]);
            $this->assertEquals("http.request", $endpoint["operation_name"]);
            $this->assertEquals($endpoint["method"] . " " . $endpoint["path"], $endpoint["resource_name"]);
        }

        // Stronger-than-Laravel assertion: every advertised route is present.
        foreach ($this->expectedEndpoints() as list($path, $method)) {
            $matches = array_filter($endpoints, function ($endpoint) use ($path, $method) {
                return $endpoint["path"] === $path && $endpoint["method"] === $method;
            });
            $this->assertCount(
                1,
                $matches,
                "Expected exactly one endpoint for $method $path, got " . count($matches)
            );
        }
    }
}
