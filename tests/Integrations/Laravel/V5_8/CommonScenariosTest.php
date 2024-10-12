<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_8;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laravel\V5_7\CommonScenariosTest
{
    public static $database = "laravel58";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_8/public/index.php';
    }
}
