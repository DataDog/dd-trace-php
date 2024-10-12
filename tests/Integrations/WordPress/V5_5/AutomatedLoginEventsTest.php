<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_5;

use DDTrace\Tests\Integrations\WordPress\AutomatedLoginEventsTestSuite;

 /**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "wp55";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_5/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_5_5/wp_2020-10-21.sql');
    }
}
