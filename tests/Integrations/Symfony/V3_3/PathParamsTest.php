<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_3;

use DDTrace\Tests\Integrations\Symfony\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_3/web/index.php';
    }

    public function testDynamicRouteWithOptionalsNotFilled()
    {
        $this->markTestSkipped("Not working on this version");
    }
}
