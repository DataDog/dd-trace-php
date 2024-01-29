<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_0;

class ConsoleCommandTest extends \DDTrace\Tests\Integrations\Symfony\V6_2\ConsoleCommandTest
{
    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_0/bin/console';
    }
}
