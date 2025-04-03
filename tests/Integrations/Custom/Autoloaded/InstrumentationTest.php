<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class InstrumentationTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
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

    public function testInstrumentation()
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug extension is loaded');
        }

        $this->resetRequestDumper();

        $this->call(GetSpec::create("autoloaded", "/simple"));
        usleep(500000);
        $response = $this->retrieveDumpedData($this->untilTelemetryRequest("spans_created"));

        $this->assertGreaterThanOrEqual(3, $response);
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
            return strpos($i["name"], "ext-") !== 0;
        }));
        // Not asserting app-closing, this is not expected to happen until shutdown

        $allMetrics = [];
        foreach ($metrics as $m) {
            foreach ($m["payload"]["series"] as $s) {
                $allMetrics[$s["metric"]] = $s;
            }
        }
        $this->assertArrayHasKey("spans_created", $allMetrics);
        $this->assertEquals("tracers", $allMetrics["spans_created"]["namespace"]);
        $this->assertEquals("spans_created", $allMetrics["spans_created"]["metric"]);
        $this->assertEquals(["integration_name:datadog"], $allMetrics["spans_created"]["tags"]);

        $this->call(GetSpec::create("autoloaded", "/pdo"));
        usleep(500000);
        $found_telemetry = false;
        $found_app_integrations_change = false;
        $response = $this->retrieveDumpedData(function ($request) use (&$found_telemetry, &$found_app_integrations_change) {
            if (strpos($request["uri"] ?? "", "/telemetry/") === 0 && strpos($request["body"] ?? "", "spans_created") !== false) {
                $found_telemetry = true;
            }
            if (strpos($request["body"] ?? "", "app-integrations-change") !== false) {
                $found_app_integrations_change = true;
            }
            return $found_telemetry && $found_app_integrations_change;
        }, true);

        $this->assertGreaterThanOrEqual(3, $response);
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
                "name" => "filesystem",
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

        $allMetrics = [];
        foreach ($metrics as $m) {
            foreach ($m["payload"]["series"] as $s) {
                $allMetrics[$s["metric"]] = $s;
            }
        }
        $this->assertArrayHasKey("spans_created", $allMetrics);
        $this->assertEquals("tracers", $allMetrics["spans_created"]["namespace"]);
        $this->assertEquals("spans_created", $allMetrics["spans_created"]["metric"]);
        $this->assertEquals(["integration_name:pdo"], $allMetrics["spans_created"]["tags"]);
    }
}
