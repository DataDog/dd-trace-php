<?php

namespace DDTrace\Tests\Integrations\Laravel\V10_x;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laravel\V9_x\CommonScenariosTest
{
    public static $database = "laravel10";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_10_x/public/index.php';
    }
}
