<?php

namespace DDTrace\Tests\Integrations\CLI\Symfony\V6_2;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class CommonScenariosTest extends IntegrationTestCase
{
    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../../Frameworks/Symfony/Version_6_2/bin/console';
    }

    public function testCommand()
    {
        list($traces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
        ], [], 'about');

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'console',
                    'console',
                    'cli',
                    'console'
                )->withChildren([
                    SpanAssertion::build(
                        'symfony.console.terminate',
                        'symfony',
                        'cli',
                        'symfony.console.terminate'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony'
                    ]),
                    SpanAssertion::build(
                        'symfony.console.command.run',
                        'symfony',
                        'cli',
                        'about'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        'symfony.console.command.class' => 'Symfony\Bundle\FrameworkBundle\Command\AboutCommand'
                    ]),
                    SpanAssertion::build(
                        'symfony.console.command',
                        'symfony',
                        'cli',
                        'symfony.console.command'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony'
                    ]),
                    SpanAssertion::build(
                        'symfony.httpkernel.kernel.boot',
                        'symfony',
                        'web',
                        'App\Kernel'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony'
                    ]),
                ]),
            ]
        );
    }

    public function testLongRunningCommandWithoutRootSpan()
    {
        list($traces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
        ], [], 'about');

        $this->assertFlameGraph(
            $traces,
            []
        );
    }
}
