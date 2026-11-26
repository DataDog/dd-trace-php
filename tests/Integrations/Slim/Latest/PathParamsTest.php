<?php

namespace DDTrace\Tests\Integrations\Slim\Latest;

use DDTrace\Tests\Integrations\Slim\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Latest/public/index.php';
    }
}
