<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_8;

use DDTrace\Tests\Integrations\Laravel\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static $database = "laravel58";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_8/public/index.php';
    }
}
