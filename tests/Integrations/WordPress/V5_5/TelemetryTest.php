<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_5;

use DDTrace\Tests\Integrations\WordPress\TelemetryTestSuite;

 /**
 * @group appsec
 */
class TelemetryTest extends TelemetryTestSuite
{
    public static $database = "wp55";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_5/index.php';
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql-integration;dbname=test', 'test', 'test');
    }
}
