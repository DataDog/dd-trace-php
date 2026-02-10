<?php

namespace DDTrace\Tests\Integrations\WordPress\V6_1;

use DDTrace\Tests\Integrations\WordPress\TelemetryTestSuite;

 /**
 * @group appsec
 */
class TelemetryTest extends TelemetryTestSuite
{
    public static $database = "wp61";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_6_1/index.php';
    }

    protected function expectedEndpoints() {
        return [
            [
                "method" => "GET",
                "operation_name" => "http.request",
                "path" => "/?page_id=2",
                "resource_name" => "GET /?page_id=2",
            ],
            [
                "method" => "GET",
                "operation_name" => "http.request",
                "path" => "/?p=1",
                "resource_name" => "GET /?p=1",
            ]
        ];
    }
}
