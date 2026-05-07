<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_3;

class ConsoleCommandTest extends \DDTrace\Tests\Integrations\Symfony\V6_2\ConsoleCommandTest
{
    public static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_3/bin/console';
    }
}
