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
            'DD_TRACE_TELEMETRY_ENABLED' => 1,
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
        $this->resetRequestDumper();

        $this->call(GetSpec::create("autoloaded", "/simple"));
        $response = $this->retrieveDumpedData();
        if (!$response) {
            $this->fail("Go no response from request-dumper");
        }

        $this->assertCount(3, $response);
        $payloads = $this->readTelemetryPayloads($response);
        $this->assertEquals("app-started", $payloads[0]["request_type"]);
        $this->assertContains([
            "name" => "datadog.agent_host",
            "value" => "request-replayer",
            "origin" => "EnvVar",
        ], $payloads[0]["payload"]["configuration"]);
        $this->assertEquals("app-dependencies-loaded", $payloads[1]["request_type"]);
        $this->assertEquals([[
            "name" => "nikic/fast-route",
            "version" => "v1.3.0",
        ]], $payloads[1]["payload"]["dependencies"]);
        // Not asserting app-closing, this is not expected to happen until shutdown

        $this->call(GetSpec::create("autoloaded", "/pdo"));

        $response = $this->retrieveDumpedData();
        if (!$response) {
            $this->fail("Go no response from request-dumper");
        }

        $this->assertCount(3, $response);
        $payloads = $this->readTelemetryPayloads($response);
        $this->assertEquals("app-started", $payloads[0]["request_type"]);
        $this->assertEquals("app-dependencies-loaded", $payloads[1]["request_type"]);
        $this->assertEquals("app-integrations-change", $payloads[2]["request_type"]);
        $this->assertEquals([[
            "name" => "pdo",
            "enabled" => true,
        ]], $payloads[2]["payload"]["integrations"]);
    }
}
