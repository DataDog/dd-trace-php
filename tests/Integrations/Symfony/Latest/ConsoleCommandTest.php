<?php

namespace DDTrace\Tests\Integrations\Symfony\Latest;

class ConsoleCommandTest extends \DDTrace\Tests\Integrations\Symfony\V6_2\ConsoleCommandTest
{
    public static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Latest/bin/console';
    }
}
