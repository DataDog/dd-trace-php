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

    private function retrieveTelemetryDataForService(string $serviceName)
    {
        $telemetryEvents = [];
        $telemetryMetrics = [];
        $this->retrieveDumpedData(function ($request) use ($serviceName, &$telemetryEvents, &$telemetryMetrics) {
            static $received;
            if (!is_array($received)) {
                $received = [];
            }

            $body = json_decode($request["body"], true);
            $request["body"] = $body;

            if (strpos($request["uri"], "/telemetry/") !== 0) {
                return false;
            }
            if ($body["application"]["service_name"] !== $serviceName) {
                return false;
            }

            if ($body["request_type"] === "message-batch") {
                foreach ($body["payload"] as $payload) {
                    if ($payload["request_type"] === "generate-metrics") {
                        $telemetryMetrics[] = $payload;
                    } else {
                        $telemetryEvents[] = $payload;
                    }
                    $received[$payload["request_type"]] = true;
                }
            } else {
                if ($body["request_type"] === "generate-metrics") {
                    $telemetryMetrics[] = [
                        "request_type" => $body["request_type"],
                        "payload" => $body["payload"] ?? null,
                    ];
                } else {
                    $telemetryEvents[] = [
                        "request_type" => $body["request_type"],
                        "payload" => $body["payload"] ?? null,
                    ];
                }
                $received[$body["request_type"]] = true;
            }

            // Stop condition
            return isset($received["app-dependencies-loaded"])
                && isset($received["generate-metrics"])
            ;
        }, true);

        return [$telemetryEvents, $telemetryMetrics];
    }

    public function testInstrumentation()
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug extension is loaded');
        }

        $this->resetRequestDumper();

        $this->call(GetSpec::create("autoloaded", "/simple"));

        list($telemetryEvents, $telemetryMetrics) = $this->retrieveTelemetryDataForService("web.request");
        $this->assertGreaterThanOrEqual(3, $telemetryEvents);

        $this->assertEquals("app-started", $telemetryEvents[0]["request_type"]);
        $this->assertContains([
            "name" => "agent_host",
            "value" => "request-replayer",
            "origin" => "EnvVar",
        ], $telemetryEvents[0]["payload"]["configuration"]);
        $this->assertEquals("app-dependencies-loaded", $telemetryEvents[1]["request_type"]);
        $this->assertEquals([[
            "name" => "nikic/fast-route",
            "version" => "v1.3.0",
        ]], array_filter($telemetryEvents[1]["payload"]["dependencies"], function ($i) {
            return strpos($i["name"], "ext-") !== 0;
        }));
        // Not asserting app-closing, this is not expected to happen until shutdown

        $allMetrics = $this->mapMetrics($telemetryMetrics);
        $this->assertArrayHasKey("spans_created", $allMetrics);
        $this->assertEquals("tracers", $allMetrics["spans_created"]["namespace"]);
        $this->assertEquals("spans_created", $allMetrics["spans_created"]["metric"]);
        $this->assertEquals(["integration_name:datadog"], $allMetrics["spans_created"]["tags"]);

        $this->call(GetSpec::create("autoloaded", "/pdo"));

        list($telemetryEvents, $telemetryMetrics) = $this->retrieveTelemetryDataForService("unnamed-php-service");
        $this->assertGreaterThanOrEqual(3, $telemetryEvents);

        $this->assertEquals("app-started", $telemetryEvents[0]["request_type"]);
        $this->assertEquals("app-dependencies-loaded", $telemetryEvents[1]["request_type"]);
        $this->assertEquals("app-integrations-change", $telemetryEvents[2]["request_type"]);
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
        ], $telemetryEvents[2]["payload"]["integrations"]);

        $allMetrics = $this->mapMetrics($telemetryMetrics);
        $this->assertArrayHasKey("spans_created", $allMetrics);
        $this->assertEquals("tracers", $allMetrics["spans_created"]["namespace"]);
        $this->assertEquals("spans_created", $allMetrics["spans_created"]["metric"]);
        $this->assertEquals(["integration_name:pdo"], $allMetrics["spans_created"]["tags"]);
    }
}
