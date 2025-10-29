<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;


class TelemetryTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
            'DD_TRACE_DEBUG' => 'true',
            'DD_SERVICE' => 'test_symfony_23',
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
        return array_values($telemetryPayloads);
    }

    public function testAppEndpointsAreSent()
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('Xdebug extension is loaded');
        }

        $this->resetRequestDumper();

        $this->call(GetSpec::create("telemetry", "/telemetry"));
        usleep(500000);
        $response = $this->retrieveDumpedData($this->untilTelemetryRequest("app-endpoints"), true);
        $payloads = $this->readTelemetryPayloads($response);
        $endpointsPayloads = array_values(array_filter($payloads, function ($p) { return $p["request_type"] == "app-endpoints"; }));

        $this->assertCount(1, $endpointsPayloads);

        $endpointsPayload = $endpointsPayloads[0];
        $endpoints = $endpointsPayload["payload"]["endpoints"];
        $this->assertGreaterThan(0, $endpoints);
        foreach ($endpoints as $endpoint) {
            $this->assertNotEmpty($endpoint["path"]);
            $this->assertNotEmpty($endpoint["method"]);
            $this->assertEquals("http.request", $endpoint["operation_name"]);
            $this->assertEquals($endpoint["method"] . " " . $endpoint["path"], $endpoint["resource_name"]);
        }
    }
}
