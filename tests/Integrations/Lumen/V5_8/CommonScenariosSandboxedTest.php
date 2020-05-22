<?php

namespace DDTrace\Tests\Integrations\Lumen\V5_8;

use DDTrace\Tests\Integrations\Lumen\V5_6\CommonScenariosSandboxedTest as V5_6_CommonScenariosSandboxedTest;

class CommonScenariosSandboxedTest extends V5_6_CommonScenariosSandboxedTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Lumen/Version_5_8/public/index.php';
    }
}
