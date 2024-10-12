<?php

namespace DDTrace\Tests\Integrations\Symfony\V6_2;

use DDTrace\Tests\Integrations\Symfony\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static $database = "symfony62";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_6_2/public/index.php';
    }
}
