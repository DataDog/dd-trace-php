<?php

namespace DDTrace\Tests\Integrations\Laravel\V11_x;

class TelemetryTest extends \DDTrace\Tests\Integrations\Laravel\V9_x\TelemetryTest
{
    public static $database = "laravel11";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_11_x/public/index.php';
    }
}
