<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class InternalTelemetry extends WebFrameworkTestCase
{
    protected function ddTearDown()
    {
        parent::ddTearDown();

        // FIXME: The server must be restarted after each test
        // Because the telemetry data are sent only periodically?
        parent::restartAppServer();
    }

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'custom_autoloaded_app',
            'DD_TRACE_AGENT_PORT' => 80,
            'DD_AGENT_HOST' => 'request-replayer',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 1,
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

    public function provideInternalMetricWithTracer() {
        yield 'opentelemetry' => ['/opentelemetry', 'integration_name:otel'];
        yield 'opentracing' => ['/opentracing', 'integration_name:opentracing'];
        yield 'opentracing1' => ['/opentracing1', 'integration_name:opentracing'];
    }

    /**
     * @dataProvider provideInternalMetricWithTracer
     */
    public function testInternalMetricWithTracer($path, $tag)
    {
        $this->resetRequestDumper();

        $this->call(GetSpec::create("autoloaded", $path));
        $response = $this->retrieveDumpedData();
        if (!$response) {
            $this->fail("Go no response from request-dumper");
        }

        $payloads = $this->readTelemetryPayloads($response);
        $isMetric = function (array $payload) {
            return 'generate-metrics' === $payload['request_type'];
        };
        $metrics = array_values(array_filter($payloads, $isMetric));

        $this->assertCount(1, $metrics);
        $this->assertEquals("generate-metrics", $metrics[0]["request_type"]);
        $this->assertEquals("tracers", $metrics[0]["payload"]["series"][0]["namespace"]);
        $this->assertEquals("spans_created", $metrics[0]["payload"]["series"][0]["metric"]);
        $this->assertEquals([$tag], $metrics[0]["payload"]["series"][0]["tags"]);
    }
}
