<?php

namespace DDTrace\Tests\Integrations\Kafka;

use DDTrace\Tests\Common\IntegrationTestCase;

class KafkaTest extends IntegrationTestCase
{
    private static $host = 'kafka-integration';
    private static $port = '9092';

    const FIELDS_TO_IGNORE = [
        'metrics.php.compilation.total_time_ms',
        'metrics.php.memory.peak_usage_bytes',
        'metrics.php.memory.peak_real_usage_bytes',
        'meta.error.stack',
        'metrics.messaging.kafka.message_offset'
    ];

    public static function getTestedLibrary()
    {
        return 'ext-rdkafka';
    }

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
        $topicDsm = $producer->newTopic('test-dsm', $topicConf);
        $topicClusterId = $producer->newTopic('test-cluster-id', $topicConf);
        $topicDefaultConf = $producer->newTopic('test-default-conf', $topicConf);
        $producer->getMetadata(false, $topicLowLevel, 5000);
        $producer->getMetadata(false, $topicHighLevel, 5000);
        $producer->getMetadata(false, $topicDsm, 5000);
        $producer->getMetadata(false, $topicClusterId, 5000);
        $producer->getMetadata(false, $topicDefaultConf, 5000);
    }

    /**
     * librdkafka has the cluster id once a client has had a metadata response. Delivery reports
     * and offset commit callbacks only come after one, so the integration's backlogs carry it.
     */
    public function testClusterId()
    {
        $output = $this->executeCli(__DIR__ . '/scripts/cluster-id.php', [], [], 'test-cluster-id', true);

        $this->assertRegularExpression("/^unreachable producer: NULL$/m", $output);
        $this->assertRegularExpression("/^delivery report: '[A-Za-z0-9_-]+'$/m", $output);
        preg_match("/^delivery report: ('[A-Za-z0-9_-]+')$/m", $output, $matches);
        foreach (['flushed producer', 'low-level consumer', 'consumer', 'offset commit callback'] as $client) {
            $this->assertRegularExpression('/^' . $client . ': ' . preg_quote($matches[1], '/') . '$/m', $output);
        }
        $this->assertRegularExpression("/^closed consumer: NULL$/m", $output);
        $this->assertRegularExpression("/^other object: TypeError$/m", $output);
    }

    const DSM_ENV = [
        'DD_DATA_STREAMS_ENABLED' => 'true',
        'DD_SERVICE' => 'kafka_test',
        'DD_ENV' => 'dsm-test',
        'DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED' => 'false',
        'DD_TRACE_CLI_ENABLED' => 'true',
        'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
        'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
        'DD_TRACE_EXEC_ENABLED' => 'false',
    ];

    /**
     * A produced message carries the Data Streams Monitoring pathway context, and the consumer
     * receives it. Both checkpoints name the Kafka cluster, as the Java tracer's do; how their
     * tags are hashed is covered by components-rs/data_streams.rs.
     */
    public function testDataStreamsContextPropagation()
    {
        $scripts = __DIR__ . '/scripts';
        list(, $producerOutput) = $this->inCli("$scripts/producer-dsm.php", self::DSM_ENV, [], 'test-dsm', true);
        // The integration wraps the application's delivery report callback to record produce
        // offsets; the application's own callback still runs.
        $this->assertStringContains('delivered: true', $producerOutput);
        $produce = '/^checkpoint: direction:out,kafka_cluster_id:([A-Za-z0-9_-]+),topic:test-dsm,type:kafka'
            . ' -> (\d+)$/m';
        $this->assertRegularExpression($produce, $producerOutput);
        preg_match($produce, $producerOutput, $produced);
        list(, $output) = $this->inCli("$scripts/consumer-dsm.php", self::DSM_ENV, [], 'test-dsm', true);

        $this->assertRegularExpression('/^dsm-context: (\\S+)$/m', $output);
        preg_match('/^dsm-context: (\\S+)$/m', $output, $matches);
        $bytes = base64_decode($matches[1], true);
        $this->assertNotFalse($bytes);
        $this->assertSame($produced[2], sprintf('%u', unpack('P', substr($bytes, 0, 8))[1]));
        $this->assertRegularExpression(
            '/^checkpoint: direction:in,group:consumer-dsm,kafka_cluster_id:' . preg_quote($produced[1], '/')
                . ',topic:test-dsm,type:kafka -> \d+$/m',
            $output
        );
    }

    /**
     * A producer that has not had its brokers' answer yet gets the cluster id another process read
     * from a client with the same bootstrap servers, through the sidecar.
     */
    public function testClusterIdSharedBetweenProcesses()
    {
        $learned = $this->executeCli(__DIR__ . '/scripts/cluster-id-shared.php', self::DSM_ENV, [], 'learn', true);
        $this->assertRegularExpression("/^learn: '[A-Za-z0-9_-]+'$/m", $learned);
        preg_match("/^learn: ('[A-Za-z0-9_-]+')$/m", $learned, $matches);
        $read = $this->executeCli(__DIR__ . '/scripts/cluster-id-shared.php', self::DSM_ENV, [], 'read', true);
        $this->assertRegularExpression('/^read: ' . preg_quote($matches[1], '/') . '$/m', $read);
    }

    /**
     * A producer whose brokers never answer waits for the cluster id on its first produce only:
     * waiting on every produce would hold up all of them for as long as the brokers stay down.
     */
    public function testOnlyTheFirstProduceWaitsForTheClusterId()
    {
        $output = $this->executeCli(
            __DIR__ . '/scripts/producer-unreachable.php',
            self::DSM_ENV,
            [],
            'test-cluster-id',
            true
        );
        $this->assertRegularExpression('/^produce times: \d+( \d+){4}$/m', $output);
        preg_match('/^produce times: (.*)$/m', $output, $matches);
        $times = array_map('intval', explode(' ', $matches[1]));
        // Without a wait, a produce only queues the message.
        $this->assertLessThan(50, max(array_slice($times, 1)), $output);
    }

    /**
     * A producer that has not had its brokers' answer yet, with bootstrap servers no process has
     * shared a cluster id for, waits for the answer to tag its first produce.
     */
    public function testFirstProduceWaitsForTheClusterId()
    {
        list(, $output) = $this->inCli(
            __DIR__ . '/scripts/producer-dsm.php',
            self::DSM_ENV,
            [],
            ['test-cluster-id', 'first'],
            true
        );
        $this->assertRegularExpression(
            '/^checkpoint: direction:out,kafka_cluster_id:[A-Za-z0-9_-]+,topic:test-cluster-id,type:kafka -> \d+$/m',
            $output
        );
    }

    /**
     * A Producer constructed without a Conf, or with null, is given an empty one, so that the
     * integration's delivery report callback records its produce offsets too.
     */
    public function testDataStreamsProduceOffsetsWithoutConf()
    {
        $env = [
            'DD_DATA_STREAMS_ENABLED' => 'true',
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
            'DD_TRACE_EXEC_ENABLED' => 'false',
        ];
        $script = __DIR__ . '/scripts/producer-default-conf.php';
        $output = $this->executeCli($script, $env, [], 'test-default-conf', true);

        foreach (['no conf', 'null conf'] as $case) {
            $this->assertRegularExpression(
                '/^' . $case . ': topic:test-default-conf,type:kafka_produce,partition:\d+'
                    . '(,kafka_cluster_id:[A-Za-z0-9_-]+)?$/m',
                $output
            );
        }
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
                'DD_CODE_ORIGIN_MAX_USER_FRAMES' => '0',
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
                'DD_CODE_ORIGIN_MAX_USER_FRAMES' => '0',
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
            $conf->set('bootstrap.servers', 'kafka-integration:9092');
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
                'DD_CODE_ORIGIN_MAX_USER_FRAMES' => '0',
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
                'DD_CODE_ORIGIN_MAX_USER_FRAMES' => '0',
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
                'DD_CODE_ORIGIN_MAX_USER_FRAMES' => '0',
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
                'DD_CODE_ORIGIN_MAX_USER_FRAMES' => '0',
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
