<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_7;

use DDTrace\Tests\Integrations\Laravel\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static $database = "laravel57";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_7/public/index.php';
    }

    public static function getTestedLibrary()
    {
        return 'laravel/framework';
    }
}