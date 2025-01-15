<?php

namespace DDTrace\Tests\Integrations\Laravel\Latest;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laravel\V9_x\CommonScenariosTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Latest/public/index.php';
    }
}
