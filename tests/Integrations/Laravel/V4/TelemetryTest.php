<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tests\Integrations\Laravel\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static $database = "laravel42";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_4_2/public/index.php';
    }

    public static function getTestedLibrary()
    {
        return 'laravel/framework';
    }
}