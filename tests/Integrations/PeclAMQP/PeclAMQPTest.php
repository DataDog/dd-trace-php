<?php

namespace DDTrace\Tests\Integrations\PeclAMQP;

use DDTrace\Tests\Common\IntegrationTestCase;

class PeclAMQPTest extends IntegrationTestCase
{
    const FIELDS_TO_IGNORE = [
        'metrics.php.compilation.total_time_ms',
        'metrics.php.memory.peak_usage_bytes',
        'metrics.php.memory.peak_real_usage_bytes',
        'meta.error.stack',
    ];

    protected function connectionToServer()
    {
        return new \AMQPConnection([
            'host' => 'rabbitmq_integration',
            'port' => 5672,
            'login' => 'guest',
            'password' => 'guest',
            'read_timeout' => 1,
        ]);
    }

    public function testHelloWorld()
    {
        self::putEnv('DD_INSTRUMENTATION_TELEMETRY_ENABLED=false');
        self::putEnv('DD_TRACE_DEBUG=true');

        $this->isolateTracerSnapshot(function () {
            $cnn = $this->connectionToServer();
            $cnn->disconnect();
        });
    }

    public function testDistributedTracing()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42');

        list($producerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/send.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
            ],
            [],
            null,
            true
        );

        //echo json_encode($producerTraces, JSON_PRETTY_PRINT) . PHP_EOL;
        //echo $output;

        $this->snapshotFromTraces(
            $producerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.pecl_amqp_test.test_distributed_tracing_producer'
        );

        list($consumerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/receive.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
            ],
            [],
            null,
            true
        );

        //echo json_encode($consumerTraces, JSON_PRETTY_PRINT) . PHP_EOL;
        //echo $output;

        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.pecl_amqp_test.test_distributed_tracing_consumer'
        );
    }
}
