<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

class TelemetryTest extends \DDTrace\Tests\Integrations\Laravel\V5_7\TelemetryTest
{
    public static $database = "laravel8";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }
}
