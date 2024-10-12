<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_5;

use DDTrace\Tests\Integrations\WordPress\PathParamsTestSuite;

 /**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static $database = "wp55";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_5/index.php';
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }
}
