<?php

namespace DDTrace\Tests\Integrations\Lumen\V5_8;

use DDTrace\Tests\Integrations\Lumen\V5_6\CommonScenariosTest as V5_6_CommonScenariosTest;

class CommonScenariosTest extends V5_6_CommonScenariosTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Lumen/Version_5_8/public/index.php';
    }
}
