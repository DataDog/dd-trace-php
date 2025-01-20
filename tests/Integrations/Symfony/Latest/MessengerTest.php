<?php

namespace DDTrace\Tests\Integrations\Symfony\Latest;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class MessengerTest extends WebFrameworkTestCase
{
    public static $database = "symfonyLatest";

    const FIELDS_TO_IGNORE = [
        'metrics.php.compilation.total_time_ms',
        'metrics.php.memory.peak_usage_bytes',
        'metrics.php.memory.peak_real_usage_bytes',
        'meta.error.stack',
        'meta._dd.p.tid',
        'meta.messaging.message_id',
        'meta.messaging.symfony.redelivered_at',
        'metrics.messaging.symfony.delay',
    ];

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Latest/public/index.php';
    }

    public static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Latest/bin/console';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/messenger';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_AUTO_FLUSH_ENABLED' => '1',
            'DD_TRACE_CLI_ENABLED' => '1',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_DEBUG' => 'true',
            'DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES' => 'true',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
        ]);
    }

    public function testAsyncSuccess()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create('Lucky number', '/lucky/number');
            $this->call($spec);
        }, self::FIELDS_TO_IGNORE);

        list($consumerTraces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS' => 'true',
            'DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES' => 'true',
            'DD_TRACE_DEBUG' => 'true',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
        ], [], ['messenger:consume', 'async', '--limit=1']);

        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.symfony.latest.messenger_test.test_async_success_consumer',
            true
        );
    }

    public function testAsyncFailure()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create('Lucky fail', '/lucky/fail');
            $this->call($spec);
        }, self::FIELDS_TO_IGNORE);

        list($consumerTraces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS' => 'true',
            'DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES' => 'true',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
        ], [], ['messenger:consume', 'async', '--limit=1']);

        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.symfony.latest.messenger_test.test_async_failure_consumer',
            true
        );
    }

    public function testAsyncWithTracerDisabledOnConsume()
    {
        // GH Issue: https://github.com/DataDog/dd-trace-php/pull/2749#issuecomment-2467409884

        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create('Lucky number', '/lucky/number');
            $this->call($spec);
        }, self::FIELDS_TO_IGNORE);

        list($output, $exitCode) = $this->executeCli(
            self::getConsoleScript(),
            [],
            ['ddtrace.disable' => 'true'],
            ['messenger:consume', 'async', '--limit=1'],
            true,
            true,
            true
        );

        $this->assertEquals(0, $exitCode, "Command failed with exit code 1. Output: $output");
    }
}
