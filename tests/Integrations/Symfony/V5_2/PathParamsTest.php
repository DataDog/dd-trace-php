<?php

namespace DDTrace\Tests\Integrations\Symfony\V5_2;

use DDTrace\Tests\Integrations\Symfony\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static $database = "symfony52";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_5_2/public/index.php';
    }
}
