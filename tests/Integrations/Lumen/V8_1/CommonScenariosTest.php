<?php

namespace DDTrace\Tests\Integrations\Lumen\V8_1;

use DDTrace\Tests\Integrations\Lumen\V5_6\CommonScenariosTest as V5_6_CommonScenariosTest;

class CommonScenariosTest extends V5_6_CommonScenariosTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Lumen/Version_8_1/public/index.php';
    }
}
