<?php

namespace DDTrace\Tests\Integrations\Symfony\V5_2;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class ConsoleCommandTest extends IntegrationTestCase
{
    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_5_2/bin/console';
    }

    public function testScenario()
    {
        list($traces) = $this->inCli(self::getConsoleScript(), [], [], 'about');

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build('console', 'console', 'cli', 'console')
                    ->withChildren([
                        SpanAssertion::exists('symfony.console.terminate', 'symfony.console.terminate'),
                        SpanAssertion::exists('symfony.console.command', 'symfony.console.command'),
                        SpanAssertion::exists('symfony.httpkernel.kernel.boot', 'App\Kernel'),
                    ]),
            ]
        );
    }
}
