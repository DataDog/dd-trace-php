<?php

declare(strict_types=1);

namespace Benchmarks\API;

use Benchmarks\DataStreamsTrait;

class DataStreamsBench
{
    use DataStreamsTrait;

    // Distinct pathways set by benchProduceCheckpointManyTopics: half of the 128 checkpoint slots
    // the sidecar's table has per bucket, so that the table never fills up and drops checkpoints.
    const TOPICS = 64;

    // Partitions benchTrackBacklog records offsets for, well within the 128 slots of the sidecar's
    // backlog table.
    const PARTITIONS = 16;

    // Size of the messages, in bytes. The integrations report one with every checkpoint.
    const PAYLOAD_SIZE = 512;

    /** @var string the context of a produce, as a consumer would receive it */
    private $context;

    /** @var string[][] */
    private $topicTags = [];

    /** @var int */
    private $topic = 0;

    /** @var string[][] */
    private $partitionTags = [];

    /** @var int */
    private $offset = 0;

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp")
     * @Warmup(1)
     */
    public function benchProduceCheckpoint()
    {
        \DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:orders', 'type:kafka'], null, self::PAYLOAD_SIZE);
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp")
     * @Warmup(1)
     */
    public function benchConsumeCheckpoint()
    {
        \DDTrace\Internal\dsm_set_checkpoint(
            ['direction:in', 'group:order-processors', 'topic:orders', 'type:kafka'],
            $this->context,
            self::PAYLOAD_SIZE
        );
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp")
     * @Warmup(1)
     */
    public function benchProduceCheckpointManyTopics()
    {
        \DDTrace\Internal\dsm_set_checkpoint($this->topicTags[$this->topic], null, self::PAYLOAD_SIZE);
        $this->topic = ($this->topic + 1) % self::TOPICS;
    }

    /**
     * What the AMQP integration does around each message it hands to a basic_consume callback.
     *
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp")
     * @Warmup(1)
     */
    public function benchConsumeCheckpointInCallback()
    {
        \DDTrace\Internal\dsm_push_pathway();
        \DDTrace\Internal\dsm_set_checkpoint(
            ['direction:in', 'topic:orders', 'type:rabbitmq'],
            $this->context,
            self::PAYLOAD_SIZE
        );
        \DDTrace\Internal\dsm_pop_pathway();
    }

    /**
     * The offset of a produced Kafka message, as the integration records it from delivery reports.
     *
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp")
     * @Warmup(1)
     */
    public function benchTrackBacklog()
    {
        \DDTrace\Internal\dsm_track_backlog($this->partitionTags[$this->offset % self::PARTITIONS], $this->offset);
        $this->offset++;
    }

    public function setUp()
    {
        $this->context = $this->enableDataStreams();

        for ($i = 0; $i < self::TOPICS; $i++) {
            $this->topicTags[] = ['direction:out', "topic:orders-$i", 'type:kafka'];
        }
        for ($i = 0; $i < self::PARTITIONS; $i++) {
            $this->partitionTags[] = ['topic:orders', 'type:kafka_produce', "partition:$i"];
        }
    }
}
