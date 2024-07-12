<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class MessengerTest extends WebFrameworkTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    const FIELDS_TO_IGNORE = [
        'metrics.php.compilation.total_time_ms',
        'meta.error.stack',
        'meta._dd.p.tid',
        'meta.messaging.message_id',
        'meta.messaging.symfony.redelivered_at',
        'metrics.messaging.symfony.delay',
    ];

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_0/public/index.php';
    }

    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_0/bin/console';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_AUTO_FLUSH_ENABLED' => '1',
            'DD_TRACE_CLI_ENABLED' => '1',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_DEBUG' => 'true',
            'DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES' => 'true',
        ]);
    }

    public function testAsyncSuccess()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create('Lucky number', '/lucky/number');
            $this->call($spec);
        });

        list($consumerTraces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS' => 'true',
            'DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES' => 'true',
        ], [], ['messenger:consume', 'async', '--limit=1']);

        // Filter out the orphans
        $consumerTrace = \array_values(\array_filter($consumerTraces, function ($trace) {
            return $trace[0]['metrics']['_sampling_priority_v1'] !== 0;
        }));

        echo json_encode($consumerTrace, JSON_PRETTY_PRINT) . PHP_EOL;

        $this->snapshotFromTraces(
            $consumerTrace,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.symfony.v7_0.messenger_test.test_async_success_consumer'
        );
    }

    public function testAsyncFailure()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $spec = GetSpec::create('Lucky fail', '/lucky/fail');
            $this->call($spec);
        });

        list($consumerTraces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_EXEC_ENABLED' => 'false',
            'DD_SERVICE' => 'symfony_messenger_test',
            'DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS' => 'true',
            'DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES' => 'true',
        ], [], ['messenger:consume', 'async', '--limit=1']);

        // Filter out the orphans
        $consumerTrace = \array_values(\array_filter($consumerTraces, function ($trace) {
            return $trace[0]['metrics']['_sampling_priority_v1'] !== 0;
        }));

        $this->snapshotFromTraces(
            $consumerTrace,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.symfony.v7_0.messenger_test.test_async_failure_consumer'
        );
    }
}
