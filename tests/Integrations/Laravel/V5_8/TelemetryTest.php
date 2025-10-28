<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_8;

class TelemetryTest extends \DDTrace\Tests\Integrations\Laravel\V5_7\TelemetryTest
{
    public static $database = "laravel58";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_8/public/index.php';
    }
}
