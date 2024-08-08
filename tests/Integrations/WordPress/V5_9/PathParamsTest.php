<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_9;

use DDTrace\Tests\Integrations\WordPress\PathParamsTestSuite;

 /**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static $database = "wp59";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_9/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_5_5/wp_2020-10-21.sql');
    }
}
