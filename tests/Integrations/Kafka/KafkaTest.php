<?php

namespace DDTrace\Tests\Integrations\Kafka;

use DDTrace\Tests\Common\IntegrationTestCase;

class KafkaTest extends IntegrationTestCase
{
    private static $host = 'kafka_integration';
    private static $port = '9092';

    const FIELDS_TO_IGNORE = [
        'metrics.php.compilation.total_time_ms',
        'metrics.php.memory.peak_usage_bytes',
        'metrics.php.memory.peak_real_usage_bytes',
        'meta.error.stack',
    ];

    public function testDistributedTracingHighLevel()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42');

        list($producerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/producer.php',
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

        echo $output;
        echo json_encode($producerTraces, JSON_PRETTY_PRINT) . PHP_EOL;
        /*
        $this->snapshotFromTraces(
            $producerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_high_level_producer'
        );
        */

        list($consumerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/consumer-highlevel.php',
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

        echo $output;
        echo json_encode($consumerTraces, JSON_PRETTY_PRINT) . PHP_EOL;

        /*
        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_high_level_consumer'
        );
        */
    }

    public function testDistributedTracingLowLevel()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42');

        list($producerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/producer.php',
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

        echo $output;
        echo json_encode($producerTraces, JSON_PRETTY_PRINT) . PHP_EOL;
        /*
        $this->snapshotFromTraces(
            $producerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_low_level_producer'
        );
        */

        list($consumerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/consumer-lowlevel.php',
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

        echo $output;
        echo json_encode($consumerTraces, JSON_PRETTY_PRINT) . PHP_EOL;

        /*
        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_low_level_consumer'
        );
        */
    }
}
