<?php

namespace DDTrace\Tests\OpenTracing;

use DDTrace\Tests\Common\CLITestCase;

final class InternalTelemetryTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../Frameworks/Custom/OpenTracing/index.php';
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

        // Filter the payloads from the trace background sender
        return array_values(array_filter($telemetryPayloads, function($p) { return ($p["application"]["service_name"] ?? "") != "background_sender-php-service"; }));
    }

    public function testInternalMetricWithOpenTracing()
    {
        $this->resetRequestDumper();

        $this->executeCommand();

        $requests = $this->retrieveDumpedData($this->untilTelemetryRequest("spans_created"));

        $payloads = $this->readTelemetryPayloads($requests);
        $isMetric = function (array $payload) {
            return 'generate-metrics' === $payload['request_type'];
        };
        $metrics = array_values(array_filter($payloads, $isMetric));

        $this->assertCount(1, $metrics);
        $this->assertEquals("generate-metrics", $metrics[0]["request_type"]);
        $this->assertEquals("tracers", $metrics[0]["payload"]["series"][0]["namespace"]);
        $this->assertEquals("spans_created", $metrics[0]["payload"]["series"][0]["metric"]);
        $this->assertEquals(["integration_name:opentracing"], $metrics[0]["payload"]["series"][0]["tags"]);
    }
}
