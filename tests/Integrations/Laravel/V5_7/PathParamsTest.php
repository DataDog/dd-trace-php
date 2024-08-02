<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_7;

use DDTrace\Tests\Integrations\Laravel\PathParamsTestSuite;

/**
 * @group appsec
 */
class PathParamsTest extends PathParamsTestSuite
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_7/public/index.php';
    }
}
