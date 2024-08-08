<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tests\Integrations\Laravel\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "laravel8";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }
}
