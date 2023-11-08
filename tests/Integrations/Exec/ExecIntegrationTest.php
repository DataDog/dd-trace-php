<?php

namespace DDTrace\Tests\Integrations\Exec;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use PHPUnit\Util\Exception;

class ExecIntegrationTest extends IntegrationTestCase
{
    const ALL_SHELL_FUNCTIONS = [
        'exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open',
    ];

    public function ddSetUp()
    {
        parent::ddSetUp();
        IntegrationsLoader::load();
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'LIN') {
            $this->markTestSkipped('This test is skipped on non-Linux systems.');
        }
    }

    /**
     * @dataProvider allShellFunctionsProvider
     */
    public function testBasicShell($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = ['ret_args' => true];
            $res = self::doShell($sf, $opts);
            $this->assertEquals('foo', $res);
            if ($sf != 'shell_exec') {
                $this->assertEquals(33, $opts['exit_code']);
            }
        });
        $expectedTags = [
            'cmd.shell' => 'echo -n foo; exit 33',
            'component' => 'subprocess',
        ];
        if ($sf != 'shell_exec') {
            $expectedTags['cmd.exit_code'] = '33';
        }
        $this->assertSpans($traces, [
            SpanAssertion::build('command_execution', $traces[0][0]['service'], 'system', 'sh')
                ->withExactTags($expectedTags)
        ]);
    }

    /**
     * Test functions that need to have their arguments changed in order to
     * receive the exit code.
     * @dataProvider alterArgumentFunctionsProvider
     * @param $sf
     * @return void
     * @throws \Exception
     */
    public function testShellUnpassedArguments($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = ['ret_args' => false];

            $res = self::doShell($sf, $opts);
            $this->assertEquals('foo', $res);
        });
        $expectedTags = [
            'cmd.shell' => 'echo -n foo; exit 33',
            'component' => 'subprocess',
            'cmd.exit_code' => '33',
        ];
        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            $expectedTags
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    /**
     * Test for proc_open and popen, where the handle is unset rather then closed.
     * @dataProvider handleShellFunctionsProvider
     * @param $sf
     * @return void
     * @throws \Exception
     */
    public function testUnsetShell($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = ['ret_args' => true, 'unset' => true];
            $res = self::doShell($sf, $opts);
            $this->assertEquals('foo', $res);
        });
        $expectedTags = [
            'cmd.shell' => 'echo -n foo; exit 33',
            'component' => 'subprocess',
            'cmd.exit_code' => '33'
        ];
        $this->assertSpans($traces, [
            SpanAssertion::build('command_execution', $traces[0][0]['service'], 'system', 'sh')
                ->withExactTags($expectedTags)
        ]);
    }

    /**
     * Test where the process is killed with a signal.
     * @dataProvider allShellFunctionsProvider
     * @param $sf
     * @return void
     */
    public function testKillShell($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = [
                'ret_args' => true,
                'cmd_variant' => 'kill'
            ];
            $res = self::doShell($sf, $opts);
            $this->assertEquals('foo', $res);
            if ($sf != 'shell_exec') {
                $this->assertEquals(9, $opts['exit_code']);
            }
        });
        $expectedTags = [
            'cmd.shell' => 'echo -n foo; kill -9 $$',
            'component' => 'subprocess',
        ];
        if ($sf != 'shell_exec') {
            $expectedTags['cmd.exit_code'] = '9';
        }
        if ($sf == 'proc_open') {
            $expectedTags['error.message'] = 'The process was terminated by a signal';
        }
        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            $expectedTags,
            null,
            isset($expectedTags['error.message'])
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    /**
     * No exit code if the process opened by proc_open() is not finished
     * by the time the handle is closed.
     * @return void
     */
    public function testProcOpenUnfinished()
    {
        $traces = $this->isolateTracer(function () {
            $opts = [
                'cmd_variant' => 'kill_after_sleep',
                'unset' => true,
                'no_wait' => true,
            ];
            self::doShell('proc_open', $opts);
        });

        $expectedTags = [
            'cmd.shell' => 'echo -n foo 2>/dev/null; sleep 5; kill -9 $$',
            'component' => 'subprocess',
        ];
        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            $expectedTags
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    /**
     * After the span for popen() is created neither the active span nor the active stack changes.
     * @return void
     */
    public function testPopenStateAfterSpanOpen()
    {
        $activeSpan = \DDTrace\active_span();
        $activeStack = \DDTrace\active_stack();
        $f = popen('true', 'rb');

        $this->assertSame($activeStack, \DDTrace\active_stack());
        $this->assertSame($activeSpan, \DDTrace\active_span());
    }

    /**
     * After the span for proc_open() is created neither the active span nor the active stack changes.
     * @return void
     */
    public function testProcOpenStateAfterSpanOpen()
    {
        $activeSpan = \DDTrace\active_span();
        $activeStack = \DDTrace\active_stack();
        $f = proc_open('true', [], $pipes);

        $this->assertSame($activeStack, \DDTrace\active_stack());
        $this->assertSame($activeSpan, \DDTrace\active_span());
    }

    /**
     * Calling the rshutdown logic closes the spans for popen().
     * @return void
     */
    public function testPopenRshutdownClosing()
    {
        $pipe = null;
        $traces = $this->isolateTracer(function () use (&$pipe) {
            $pipe = popen('exit 33', 'rb');
            \DDTrace\Integrations\Exec\test_rshutdown();
        });

        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            [
                'cmd.shell' => 'exit 33',
                'component' => 'subprocess',
                'cmd.exit_code' => '33',
            ]
        );

        $this->assertSpans($traces, [$spanAssertion]);
        $this->assertFalse(is_resource($pipe)); // already destroyed
    }

    /**
     * Calling the rshutdown logic closes the spans for proc_open().
     * @return void
     */
    public function testProcOpenRshutdownClosing()
    {
        $h = null;
        $traces = $this->isolateTracer(function () use (&$h) {
            $h = proc_open('exit 33', [], $pipes);
            self::waitProcessExit($h);
            \DDTrace\Integrations\Exec\test_rshutdown();
        });

        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            [
                'cmd.shell' => 'exit 33',
                'component' => 'subprocess',
                'cmd.exit_code' => '33',
            ]
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    public function testProcGetStatus()
    {
        $traces = $this->isolateTracer(function () use (&$pipe) {
            $h = proc_open('exit 33', [], $pipes);
            self::waitProcessExit($h);
            $status = proc_get_status($h);
            $this->assertEquals(33, $status['exitcode']);
            $this->assertEquals(false, $status['running']);
            proc_close($h);
        });

        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            [
                'cmd.shell' => 'exit 33',
                'component' => 'subprocess',
                'cmd.exit_code' => '33',
            ]
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    public function testProcGetStatusSignal()
    {
        $traces = $this->isolateTracer(function () use (&$pipe, &$h) {
            $h = proc_open('kill -9 $$', [], $pipes);
            self::waitProcessExit($h);
            $status = proc_get_status($h);
            $this->assertEquals(9, $status['termsig']);
            $this->assertEquals(true, $status['signaled']);
            $this->assertEquals(false, $status['running']);
            proc_close($h);
        });

        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            [
                'cmd.shell' => 'kill -9 $$',
                'component' => 'subprocess',
                'cmd.exit_code' => '9',
                'error.message' => 'The process was terminated by a signal',
            ],
            null,
            true
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    /**
     * Test span for execution without a shell.
     * @throws \Exception
     */
    public function testDirectExecution()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('This test is skipped on PHP < 7.4.');
        }

        $traces = $this->isolateTracer(function () use (&$pipe) {
            $desc = [1 => ['pipe', 'wb']];
            $h = proc_open([__DIR__ . "/exit_33.sh", 'foo$', 'bar'], $desc, $pipes);
            if ($h === false) {
                throw new \Exception('Could not open process');
            }
            $data = stream_get_contents($pipes[1]);

            $this->assertEquals('foo$ bar', $data);
            $this->assertEquals(33, proc_close($h));
        });

        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'exit_33.sh',
            [
                'cmd.exec' => '["' . __DIR__ . '/exit_33.sh","foo$","bar"]',
                'component' => 'subprocess',
                'cmd.exit_code' => '33',
            ]
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    public function testDirectExecutionRedaction()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('This test is skipped on PHP < 7.4.');
        }

        $traces = $this->isolateTracer(function () use (&$pipe) {
            $desc = [1 => ['pipe', 'wb']];
            $cmd = [__DIR__ . "/exit_33.sh", '--password=foo', '-pass', 'bar'];
            $h = proc_open($cmd, $desc, $pipes);
            if ($h === false) {
                throw new \Exception('Could not open process');
            }
            stream_get_contents($pipes[1]);
            proc_close($h);
        });

        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'exit_33.sh',
            [
                'cmd.exec' => '["' . __DIR__ . '/exit_33.sh","--password=?","-pass","?"]',
                'component' => 'subprocess',
                'cmd.exit_code' => '33',
            ]
        );

        $this->assertSpans($traces, [$spanAssertion]);
    }

    /**
     * Test that blank command results in no span.
     *
     * @dataProvider allShellFunctionsProvider
     * @param $sf
     * @return void
     */
    public function testShellBlankCommand($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = ['ret_args' => true, 'cmd_variant' => 'blank'];

            try {
                $res = self::doShell($sf, $opts);
            } catch (\PHPUnit\Framework\Error\Warning | \ValueError $w) {
                // ignore warning
                $res = false;
            }
            $this->assertEquals('', $res);
        });

        // no span is created
        $this->assertEquals([], $traces);
    }

    /**
     * Test that a command with a NUL byte in it results in an error span.
     * @dataProvider functionsDisallowingNulProvider
     * @param $sf
     * @return void
     */
    public function testShellCommandWithNul($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = ['ret_args' => true, 'cmd_variant' => 'nul'];

            try {
                $res = self::doShell($sf, $opts);
            } catch (\PHPUnit\Framework\Error\Warning | \ValueError $w) {
                // ignore warning
                $res = false;
            }
            $this->assertEquals('', $res);
        });

        $spanAssertion = SpanAssertion::build(
            'command_execution',
            $traces[0][0]['service'],
            'system',
            'sh',
            [
                'cmd.shell' => "echo -n foo\x00; exit 33",
                'component' => 'subprocess',
            ],
            null,
            true
        );
        $spanAssertion->withExistingTagsNames(['error.message', 'error.type', 'error.stack']);

        $this->assertSpans($traces, [$spanAssertion]);
    }

    /**
     * Test that bad arguments result in an error span.
     *
     * @dataProvider allShellFunctionsProvider
     * @param $sf
     * @return void
     */
    public function testShellOtherBadArguments($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = ['ret_args' => true, 'bad_args' => 'true'];

            try {
                $res = self::doShell($sf, $opts);
            } catch (\PHPUnit\Framework\Error\Warning | \ArgumentCountError $w) {
                // ignore warning
                $res = false;
            }
            $this->assertEquals('', $res);
        });

        if ($sf === 'proc_open' || $sf === 'popen') {
            // no span is created
            $this->assertEquals(0, count($traces));
        } else {
            $spanAssertion = SpanAssertion::build(
                'command_execution',
                $traces[0][0]['service'],
                'system',
                'sh',
                [
                    'cmd.shell' => "echo -n foo; exit 33",
                    'component' => 'subprocess',
                ],
                null,
                true
            );
            $spanAssertion->withExistingTagsNames(['error.message', 'error.type', 'error.stack']);

            $this->assertSpans($traces, [$spanAssertion]);
        }
    }

    /**
     * Test that the shell command is redacted.
     * @dataProvider allShellFunctionsProvider
     * @param $sf
     * @return void
     */
    public function testShellRedaction($sf)
    {
        $traces = $this->isolateTracer(function () use ($sf) {
            $opts = ['ret_args' => true, 'cmd_variant' => 'redaction'];
            self::doShell($sf, $opts);
        });

        $this->assertEquals(
            "md5(){ return; }; FOO=? BAR=? echo foo -- --password=? -pass ?; md5 ? ?",
            $traces[0][0]['meta']['cmd.shell']
        );
    }

    /**
     * Test that the shell command is truncated to 4 kB.
     * @return void
     */
    public function testShellTruncation()
    {
        $traces = $this->isolateTracer(function () {
            $opts = ['ret_args' => true, 'cmd_variant' => 'truncation'];
            self::doShell('exec', $opts);
        });

        $this->assertEquals(
            4 * 1024,
            strlen($traces[0][0]['meta']['cmd.shell'])
        );
    }

    /**
     * Test that the exec command is truncated to 4 kB.
     * @return void
     * @throws \Exception
     */
    public function testExecTruncation()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('This test is skipped on PHP < 7.4.');
        }

        $traces = $this->isolateTracer(function () use (&$pipe) {
            $desc = [1 => ['pipe', 'wb']];
            $h = proc_open(['echo', str_repeat('a', 4096), 'arg'], $desc, $pipes);
            if ($h === false) {
                throw new \Exception('Could not open process');
            }
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $this->assertEquals(0, proc_close($h));
        });

        $this->assertEquals(
            '["echo","' . str_repeat('a', 4092) . '",""]',
            $traces[0][0]['meta']['cmd.exec']
        );
    }

    private static function doShell($function, array &$opts = [])
    {
        $cmdVariant = $opts['cmd_variant'] ?? 'normal';
        if ($cmdVariant === 'kill') {
            $cmd = 'echo -n foo; kill -9 $$';
        } elseif ($cmdVariant === 'kill_after_sleep') {
            $cmd = 'echo -n foo 2>/dev/null; sleep 5; kill -9 $$';
        } elseif ($cmdVariant === 'blank') {
            $cmd = '';
        } elseif ($cmdVariant === 'nul') {
            $cmd = "echo -n foo\x00; exit 33";
        } elseif ($cmdVariant === 'redaction') {
            $cmd = 'md5(){ return; }; FOO=? BAR=? echo foo -- --password=? -pass ?; md5 ? ?';
        } else if ($cmdVariant === 'truncation') {
            $cmd = 'echo ' . str_repeat('a', 4 * 1024 - 4) . ' bar';
        } else {
            $cmd = 'echo -n foo; exit 33';
        }
        if (!isset($opts['ret_args'])) {
            // ensure there's enough space in the stack
            $f = function (...$a) {
                return $a;
            };
            $f(1, 2, 3, 4, 5, 6, 7);
        }
        if ($function === 'exec') {
            if (isset($opts['bad_args'])) {
                return exec($cmd, $g1, $g2, 33);
            }
            if (isset($opts['ret_args'])) {
                return exec($cmd, $garbage, $opts['exit_code']);
            } else {
                return exec($cmd);
            }
        } elseif ($function === 'system') {
            if (isset($opts['bad_args'])) {
                return system($cmd, $garbage, 33);
            }
            ob_start();
            try {
                if (isset($opts['ret_args'])) {
                    system($cmd, $opts['exit_code']);
                } else {
                    system($cmd);
                }
            } finally {
                return ob_get_clean();
            }
        } elseif ($function === 'passthru') {
            if (isset($opts['bad_args'])) {
                return passthru($cmd, $garbage, 33);
            }
            ob_start();
            try {
                if (isset($opts['ret_args'])) {
                    passthru($cmd, $opts['exit_code']);
                } else {
                    passthru($cmd);
                }
            } finally {
                return ob_get_clean();
            }
        } elseif ($function === 'shell_exec') {
            if (isset($opts['bad_args'])) {
                return shell_exec($cmd, 33);
            }
            return shell_exec($cmd);
        } elseif ($function === 'popen') {
            if (isset($opts['bad_args'])) {
                return popen($cmd, 'rb', 33);
            }
            $pipe = popen($cmd, 'rb');
            if ($pipe === false) {
                return false;
            }
            if (isset($opts['no_wait'])) {
                $res = '';
            } else {
                $res = stream_get_contents($pipe);
            }

            if (isset($opts['unset'])) {
                unset($pipe);
            } else {
                $opts['exit_code'] = pclose($pipe);
            }
            return $res;
        } elseif ($function === 'proc_open') {
            if (isset($opts['bad_args'])) {
                return proc_open($cmd, [], $pipes, null, null, null, 33);
            }
            $desc = [1 => ['pipe', 'wb']];
            $h = proc_open($cmd, $desc, $pipes);
            if ($h === false) {
                return false;
            }

            if (isset($opts['no_wait'])) {
                $res = '';
            } else {
                $res = stream_get_contents($pipes[1]);
            }
            unset($pipes);
            if (isset($opts['unset'])) {
                unset($h);
            } else {
                $opts['exit_code'] = proc_close($h);
            }
            return $res;
        } else {
            throw new Exception("Unknown function $function");
        }
    }

    public static function allShellFunctionsProvider()
    {
        return array_map(function ($sf) {
            return [$sf];
        }, self::ALL_SHELL_FUNCTIONS);
    }

    public static function alterArgumentFunctionsProvider()
    {
        return [
            ['exec'],
            ['system'],
            ['passthru'],
        ];
    }

    public static function functionsDisallowingNulProvider()
    {
        return [
            ['exec'],
            ['system'],
            ['passthru'],
        ];
    }

    public static function handleShellFunctionsProvider()
    {
        return [
            ['popen'],
            ['proc_open'],
        ];
    }

    /**
     * Wait for a process to exit.
     * @param $h resource a proc_open handle
     * @return void
     */
    private static function waitProcessExit($h)
    {
        $pid = \DDTrace\Integrations\Exec\proc_get_pid($h);
        if ($pid === null) {
            throw new Exception('Could not get pid');
        }

        $deadline = time() + 2;
        while (time() < $deadline) {
            $c = file_get_contents("/proc/$pid/stat");
            if ($c === false) {
                return;
            }

            if (!preg_match('/\A[^)]+\)(?:\s\S+){49}\s(\d+)/', $c, $matches)) {
                throw new Exception("Could not parse /proc/$pid/stat");
            }

            if ($matches[1] !== '0') {
                return;
            }
            usleep(10000);
        }
    }
}
