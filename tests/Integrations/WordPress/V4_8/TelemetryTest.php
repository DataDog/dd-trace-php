<?php

namespace DDTrace\Tests\Integrations\WordPress\V4_8;

use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Integrations\WordPress\TelemetryTestSuite;

 /**
 * @group appsec
 */
class TelemetryTest extends TelemetryTestSuite
{
    public static $database = "wp48";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_4_8/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_4_8/wp_2019-10-01.sql');
    }

    protected function expectedEndpoints() {
        return [
            [
                "method" => "GET",
                "operation_name" => "http.request",
                "path" => "/?p=1",
                "resource_name" => "GET /?p=1",
            ],
            [
                "method" => "GET",
                "operation_name" => "http.request",
                "path" => "/?page_id=2",
                "resource_name" => "GET /?page_id=2",
            ]
        ];
    }
}
