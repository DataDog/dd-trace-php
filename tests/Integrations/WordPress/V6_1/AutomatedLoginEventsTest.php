<?php

namespace DDTrace\Tests\Integrations\WordPress\V6_1;

use DDTrace\Tests\Integrations\WordPress\AutomatedLoginEventsTestSuite;

 /**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "wp61";
    protected $users_table = 'wp_users';

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_6_1/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_6_1/scripts/wp_initdb.sql');
    }
}
