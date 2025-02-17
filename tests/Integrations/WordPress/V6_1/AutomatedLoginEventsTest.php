<?php

namespace DDTrace\Tests\Integrations\WordPress\V6_1;

use DDTrace\Tests\Integrations\WordPress\AutomatedLoginEventsTestSuite;

 /**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "wp61";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_6_1/index.php';
    }
}
