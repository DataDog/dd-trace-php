<?php

namespace DDTrace\Tests\Integrations\CLI\Symfony\Latest;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Symfony\V6_2\CommonScenariosTest
{
    public static function getConsoleScript()
    {
        return __DIR__ . '/../../../../Frameworks/Symfony/Latest/bin/console';
    }
}