<?php

namespace DDTrace\Tests\Integrations\Laminas\Mvc\V3_3;

use DDTrace\Tests\Integrations\Laminas\Mvc\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../../Frameworks/Laminas/Mvc/Version_3_3/public/index.php';
    }
}
