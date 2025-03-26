<?php

namespace DDTrace\Tests\Integrations\CLI\Symfony\V4_4;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class CommonScenariosTest extends IntegrationTestCase
{
    const FIELDS_TO_IGNORE = [
        'metrics.php.compilation.total_time_ms',
        'metrics.php.memory.peak_usage_bytes',
        'metrics.php.memory.peak_real_usage_bytes',
        'meta.error.stack',
        'meta._dd.p.tid',
        'meta.cmd.exit_code',
    ];

    public static function getConsoleScript()
    {
        return __DIR__ . '/../../../../Frameworks/Symfony/Version_4_4/bin/console';
    }

    public function testSilencedSpansAreDropped()
    {
        list($traces) = $this->inCli(static::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'true',
        ], [], 'app:stty', false, null, true, true);

        $this->snapshotFromTraces($traces, self::FIELDS_TO_IGNORE);
    }

    public function testThrowCommand()
    {
        list($traces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
        ], [], 'app:throw');

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
                        Tag::COMPONENT => 'symfony',
                        '_dd.base_service' => 'console',
                    ]),
                    SpanAssertion::build(
                        'symfony.console.command.run',
                        'symfony',
                        'cli',
                        'app:throw'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        'symfony.console.command.class' => 'App\\Command\\ThrowCommand',
                        '_dd.base_service' => 'console',
                    ])->setError(
                        "Exception",
                        "This is an exception",
                        true
                    ),
                    SpanAssertion::build(
                        'symfony.console.error',
                        'symfony',
                        'cli',
                        'symfony.console.error'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        '_dd.base_service' => 'console',
                    ]),
                    SpanAssertion::build(
                        'symfony.console.command',
                        'symfony',
                        'cli',
                        'symfony.console.command'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        '_dd.base_service' => 'console',
                    ]),
                    SpanAssertion::build(
                        'symfony.httpkernel.kernel.boot',
                        'symfony',
                        'web',
                        'App\Kernel'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        '_dd.base_service' => 'console',
                    ])
                ])->setError("Exception", "This is an exception", true)
            ]
        );
    }

    public function testCommand()
    {
        list($traces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
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
                        Tag::COMPONENT => 'symfony',
                        '_dd.base_service' => 'console',
                    ]),
                    SpanAssertion::build(
                        'symfony.console.command.run',
                        'symfony',
                        'cli',
                        'about'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        'symfony.console.command.class' => 'Symfony\Bundle\FrameworkBundle\Command\AboutCommand',
                        '_dd.base_service' => 'console',
                    ]),
                    SpanAssertion::build(
                        'symfony.console.command',
                        'symfony',
                        'cli',
                        'symfony.console.command'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        '_dd.base_service' => 'console',
                    ]),
                    SpanAssertion::build(
                        'symfony.httpkernel.kernel.boot',
                        'symfony',
                        'web',
                        'App\Kernel'
                    )->withExactTags([
                        Tag::COMPONENT => 'symfony',
                        '_dd.base_service' => 'console',
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
            'DD_TRACE_EXEC_ENABLED' => 'false',
        ], [], 'about', false, null, false);

        $this->assertFlameGraph(
            $traces,
            []
        );
    }
}
