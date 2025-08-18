<?php

namespace DDTrace\Tests\Integrations\Laravel\V10_x;

class TelemetryTest extends \DDTrace\Tests\Integrations\Laravel\V9_x\TelemetryTest
{
    public static $database = "laravel10";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_10_x/public/index.php';
    }
}
