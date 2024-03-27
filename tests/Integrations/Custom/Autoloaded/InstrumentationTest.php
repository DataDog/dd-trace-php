<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class InstrumentationTest extends WebFrameworkTestCase
{
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

    public function testInstrumentation()
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug extension is loaded');
        }

        $this->resetRequestDumper();

        $this->call(GetSpec::create("autoloaded", "/simple"));
        $response = $this->retrieveDumpedData();
        if (!$response) {
            $this->fail("Go no response from request-dumper");
        }

        $this->assertCount(4, $response);
        $payloads = $this->readTelemetryPayloads($response);

        $isMetric = function (array $payload) {
            return 'generate-metrics' === $payload['request_type'];
        };
        $metrics = array_values(array_filter($payloads, $isMetric));
        $payloads = array_values(array_filter($payloads, function($p) use ($isMetric) { return !$isMetric($p); }));

        $this->assertEquals("app-started", $payloads[0]["request_type"]);
        $this->assertContains([
            "name" => "agent_host",
            "value" => "request-replayer",
            "origin" => "EnvVar",
        ], $payloads[0]["payload"]["configuration"]);
        $this->assertEquals("app-dependencies-loaded", $payloads[1]["request_type"]);
        $this->assertEquals([[
            "name" => "nikic/fast-route",
            "version" => "v1.3.0",
        ]], array_filter($payloads[1]["payload"]["dependencies"], function ($i) {
            $parts = explode("/", $i["name"], 2);
            return strpos($i["name"], "ext-") !== 0 && !in_array($parts[0], ["symfony", "psr", "open-telemetry", "opentracing", "php-http"]);
        }));
        // Not asserting app-closing, this is not expected to happen until shutdown

        $this->assertCount(1, $metrics);
        $this->assertEquals("generate-metrics", $metrics[0]["request_type"]);
        $this->assertEquals("tracers", $metrics[0]["payload"]["series"][0]["namespace"]);
        $this->assertEquals("spans_created", $metrics[0]["payload"]["series"][0]["metric"]);
        $this->assertEquals(["integration_name:datadog"], $metrics[0]["payload"]["series"][0]["tags"]);

        $this->call(GetSpec::create("autoloaded", "/pdo"));

        $response = $this->retrieveDumpedData();
        if (!$response) {
            $this->fail("Go no response from request-dumper");
        }

        $this->assertCount(4, $response);
        $payloads = $this->readTelemetryPayloads($response);

        $metrics = array_values(array_filter($payloads, $isMetric));
        $payloads = array_values(array_filter($payloads, function($p) use ($isMetric) { return !$isMetric($p); }));

        $this->assertEquals("app-started", $payloads[0]["request_type"]);
        $this->assertEquals("app-dependencies-loaded", $payloads[1]["request_type"]);
        $this->assertEquals("app-integrations-change", $payloads[2]["request_type"]);
        $this->assertEquals([
            [
                "name" => "pdo",
                "enabled" => true,
                'version' => null,
                'compatible' => null,
                'auto_enabled' => null,
            ],
            [
                "name" => "exec",
                "enabled" => false,
                "version" => "",
                'compatible' => null,
                'auto_enabled' => null,
            ],
            [
                "name" => "logs",
                "enabled" => false,
                "version" => "",
                'compatible' => null,
                'auto_enabled' => null,
            ]
        ], $payloads[2]["payload"]["integrations"]);

        $this->assertCount(1, $metrics);
        $this->assertEquals("generate-metrics", $metrics[0]["request_type"]);
        $this->assertEquals("tracers", $metrics[0]["payload"]["series"][0]["namespace"]);
        $this->assertEquals("spans_created", $metrics[0]["payload"]["series"][0]["metric"]);
        $this->assertEquals(["integration_name:pdo"], $metrics[0]["payload"]["series"][0]["tags"]);
    }
}
