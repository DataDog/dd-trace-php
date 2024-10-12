<?php

namespace DDTrace\Tests\Integrations\WordPress\V4_8;

use DDTrace\Tests\Integrations\WordPress\AutomatedLoginEventsTestSuite;

 /**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "wp48";

    protected $users_table = 'wp_users';

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_4_8/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_4_8/wp_2019-10-01.sql');
    }
}
