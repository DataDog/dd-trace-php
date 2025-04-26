<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tests\Integrations\Laravel\RaspEventsTestSuite;

/**
 * @group appsec
 */
class RaspEventsTest extends RaspEventsTestSuite
{
    public static $database = "laravel8";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }
}
