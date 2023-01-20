<?php

namespace DDTrace\Tests\Integrations\Symfony\V6_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class ConsoleCommandTest extends IntegrationTestCase
{
    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_6_0/bin/console';
    }

    public function testScenario()
    {
        list($traces) = $this->inCli(self::getConsoleScript(), [], [], 'about');

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build('console', 'console', 'cli', 'console')
                    ->withChildren([
                        SpanAssertion::build('symfony.console.command.run', 'symfony', 'cli', 'about')
                            ->withExactTags([
                                'symfony.console.command.class' => 'Symfony\Bundle\FrameworkBundle\Command\AboutCommand',
                                Tag::COMPONENT => 'symfony',
                            ]),
                        SpanAssertion::exists('symfony.console.command', 'symfony.console.command'),
                        SpanAssertion::exists('symfony.console.terminate', 'symfony.console.terminate'),
                        SpanAssertion::exists('symfony.httpkernel.kernel.boot', 'App\Kernel'),
                    ]),
            ]
        );
    }
}
