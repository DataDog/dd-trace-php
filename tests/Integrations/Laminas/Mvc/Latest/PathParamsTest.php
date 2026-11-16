<?php

namespace DDTrace\Tests\Integrations\Laminas\Mvc\Latest;

use DDTrace\Tests\Integrations\Laminas\Mvc\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../../Frameworks/Laminas/Mvc/Latest/public/index.php';
    }
}
