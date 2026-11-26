<?php

namespace DDTrace\Tests\Integrations\Slim\V4_8;

use DDTrace\Tests\Integrations\Slim\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_4_8/public/index.php';
    }
}
