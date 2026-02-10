<?php

namespace DDTrace\Tests\Integrations\Laravel\V9_x;

class TelemetryTest extends \DDTrace\Tests\Integrations\Laravel\V5_7\TelemetryTest
{
    public static $database = "laravel9";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_9_x/public/index.php';
    }
}
