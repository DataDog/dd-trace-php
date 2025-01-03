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

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        // Ensure topics (test-lowlevel and test-highlevel) are created
        $conf = new \RdKafka\Conf();
        $conf->set('bootstrap.servers', self::$host . ':' . self::$port);
        $producer = new \RdKafka\Producer($conf);
        $topicConf = new \RdKafka\TopicConf();
        $topicConf->set('message.timeout.ms', (string) 30000);
        $topicConf->set('request.required.acks', (string) -1);
        $topicConf->set('request.timeout.ms', (string) 5000);
        $topicLowLevel = $producer->newTopic('test-lowlevel', $topicConf);
        $topicHighLevel = $producer->newTopic('test-highlevel', $topicConf);
        $producer->getMetadata(false, $topicLowLevel, 5000);
        $producer->getMetadata(false, $topicHighLevel, 5000);
    }

    public function testDistributedTracingHighLevel()
    {
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

        $producerTrace = $producerTraces[0][0];
        $kafkaProduceSpanID = $producerTrace['span_id'];
        $kafkaProduceTraceID = $producerTrace['trace_id'];

        list($consumerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/consumer-highlevel.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
                'DD_SERVICE' => 'kafka_test',
                'DD_TRACE_EXEC_ENABLED' => 'false',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED' => 'false',
                'DD_TRACE_KAFKA_DISTRIBUTED_TRACING' => 'true',
            ],
            [],
            null,
            true
        );

        $distributedKafkaConsumeTraceID = $consumerTraces[0][0]['trace_id'];
        $distributedKafkaConsumeParentID = $consumerTraces[0][0]['parent_id'];

        $this->assertEquals($kafkaProduceTraceID, $distributedKafkaConsumeTraceID);
        $this->assertEquals($kafkaProduceSpanID, $distributedKafkaConsumeParentID);
        $this->assertCount(1, $consumerTraces[0]);
    }

    public function testSpanLinksLowLevel()
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
            'tests.integrations.kafka_test.test_span_links_low_level_producer'
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
                'DD_TRACE_KAFKA_DISTRIBUTED_TRACING' => 'false',
            ],
            [],
            $high,
            true
        );

        echo $output;

        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_span_links_low_level_consumer'
        );
    }

    public function testSpanLinksHighLevel()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42');

        list($producerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/producerv.php',
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
            'tests.integrations.kafka_test.test_span_links_high_level_producer'
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
                'DD_TRACE_KAFKA_DISTRIBUTED_TRACING' => 'false',
            ],
            [],
            null,
            true
        );

        echo $output;

        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.kafka_test.test_span_links_high_level_consumer'
        );
    }
}
