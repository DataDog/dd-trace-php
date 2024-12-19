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
        'metrics.messaging.kafka.message_offset'
    ];

    public function testDistributedTracingLowLevel()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42');

        // Get the latest offset of the test-lowlevel topic
        $this->isolateLimitedTracer(function () use (&$low, &$high) {
            $conf = new \RdKafka\Conf();
            $conf->set('bootstrap.servers', 'kafka_integration:9092');
            $conf->set('group.id', 'consumer-lowlevel');
            $conf->set('enable.partition.eof', 'true');

            $consumer = new \RdKafka\KafkaConsumer($conf);
            $consumer->queryWatermarkOffsets('test-lowlevel', 0, $low, $high, 1000);
        });

        echo "Low: $low, High: $high\n";

        list($producerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/producer.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
                'DD_SERVICE' => 'kafka_test',
                'DD_TRACE_EXEC_ENABLED' => 'false',
                'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED' => 'false',
            ],
            [],
            'test-lowlevel',
            true
        );

        echo $output;

        $this->snapshotFromTraces(
            $producerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_low_level_producer'
        );

        list($consumerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/consumer-lowlevel.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
                'DD_SERVICE' => 'kafka_test',
                'DD_TRACE_EXEC_ENABLED' => 'false',
                'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED' => 'false',
            ],
            [],
            $high,
            true
        );

        echo $output;

        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_low_level_consumer'
        );
    }

    public function testDistributedTracingHighLevel()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42');

        list($producerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/producer.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
                'DD_SERVICE' => 'kafka_test',
                'DD_TRACE_EXEC_ENABLED' => 'false',
                'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED' => 'false',
            ],
            [],
            'test-highlevel',
            true
        );

        echo $output;

        $this->snapshotFromTraces(
            $producerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_high_level_producer'
        );

        list($consumerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/consumer-highlevel.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
                'DD_SERVICE' => 'kafka_test',
                'DD_TRACE_EXEC_ENABLED' => 'false',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED' => 'false',
            ],
            [],
            null,
            true
        );

        echo $output;

        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_distributed_tracing_high_level_consumer'
        );
    }
}
