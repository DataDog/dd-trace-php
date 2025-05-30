<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_9;

use DDTrace\Tests\Integrations\WordPress\AutomatedLoginEventsTestSuite;

 /**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "wp59";
    protected $users_table = 'wp55_users';

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_9/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_5_5/wp_2020-10-21.sql');
    }
}
