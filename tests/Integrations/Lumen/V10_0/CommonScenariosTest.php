<?php

namespace DDTrace\Tests\Integrations\Lumen\V10_0;

use DDTrace\Tests\Integrations\Lumen\V5_6\CommonScenariosTest as V5_6_CommonScenariosTest;

class CommonScenariosTest extends V5_6_CommonScenariosTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Lumen/Version_10_0/public/index.php';
    }
}
