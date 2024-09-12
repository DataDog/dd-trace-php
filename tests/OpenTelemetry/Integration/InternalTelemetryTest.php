<?php

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\Tests\Common\CLITestCase;

final class InternalTelemetryTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../Frameworks/Custom/OpenTelemetry/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_OTEL_ENABLED' => 1,
        ]);
    }

    private function mapMetrics(array $telemetryMetrics)
    {
        $map = [];
        foreach ($telemetryMetrics as $m) {
            foreach ($m["payload"]["series"] as $s) {
                $map[$s["metric"]] = $s;
            }
        }

        return $map;
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

    public function testInternalMetricWithOpenTelemetry()
    {
        $this->resetRequestDumper();

        $this->executeCommand();

        $requests = $this->retrieveDumpedData($this->untilTelemetryRequest("spans_created"));

        $payloads = $this->readTelemetryPayloads($requests);
        $isMetric = function (array $payload) {
            return 'generate-metrics' === $payload['request_type'];
        };
        $telemetryMetrics = array_values(array_filter($payloads, $isMetric));

        $allMetrics = $this->mapMetrics($telemetryMetrics);
        $this->assertArrayHasKey("spans_created", $allMetrics);
        $this->assertEquals("tracers", $allMetrics["spans_created"]["namespace"]);
        $this->assertEquals("spans_created", $allMetrics["spans_created"]["metric"]);
        $this->assertEquals(["integration_name:otel"], $allMetrics["spans_created"]["tags"]);
    }
}
