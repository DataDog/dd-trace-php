<?php

namespace DDTrace\Tests\Integrations\Slim\V3_12;

use DDTrace\Tests\Integrations\Slim\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_3_12/public/index.php';
    }
}
