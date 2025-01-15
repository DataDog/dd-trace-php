<?php

namespace DDTrace\Tests\Integrations\CLI\Symfony\V7_0;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Symfony\V6_2\CommonScenariosTest
{
    public static function getConsoleScript()
    {
        return __DIR__ . '/../../../../Frameworks/Symfony/Version_7_0/bin/console';
    }
}
