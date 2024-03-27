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

    private function readTelemetryPayloads($response)
    {
        $telemetryPayloads = [];
        foreach ($response as $request) {
            if (strpos($request["uri"], "/telemetry/") === 0) {
                $json = json_decode($request["body"], true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $json) {
                    $telemetryPayloads[] = $json;
                }
            }
        }
        return $telemetryPayloads;
    }

    public function testInternalMetricWithOpenTelemetry()
    {
        $this->resetRequestDumper();

        $this->executeCommand();

        $requests = $this->retrieveDumpedData(function ($request) {
            return (strpos($request["uri"] ?? "", "/telemetry/") === 0)
                && (strpos($request["body"] ?? "", "generate-metrics") !== false)
            ;
        }, true);

        $payloads = $this->readTelemetryPayloads($requests);
        $isMetric = function (array $payload) {
            return 'generate-metrics' === $payload['request_type'];
        };
        $metrics = array_values(array_filter($payloads, $isMetric));

        $this->assertCount(1, $metrics);
        $this->assertEquals("generate-metrics", $metrics[0]["request_type"]);
        $this->assertEquals("tracers", $metrics[0]["payload"]["series"][0]["namespace"]);
        $this->assertEquals("spans_created", $metrics[0]["payload"]["series"][0]["metric"]);
        $this->assertEquals(["integration_name:otel"], $metrics[0]["payload"]["series"][0]["tags"]);
    }
}
