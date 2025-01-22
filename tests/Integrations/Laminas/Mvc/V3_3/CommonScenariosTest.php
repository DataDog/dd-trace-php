<?php

namespace DDTrace\Tests\Integrations\Laminas\Mvc\V3_3;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laminas\Mvc\Latest\CommonScenariosTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../../Frameworks/Laminas/Mvc/Version_3_3/public/index.php';
    }
}