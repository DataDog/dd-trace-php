<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use Benchmarks\DataStreamsTrait;
use DDTrace\Integrations\AMQP\AMQPIntegration;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * The Data Streams Monitoring work the AMQP integration does for each message: the edge tags, the
 * message's size, which takes encoding its headers, the checkpoint, and, on publish, setting the
 * context header. Measured through the helpers the integration's hooks call, as there is no
 * broker to publish to or consume from.
 */
class AMQPDataStreamsBench
{
    use DataStreamsTrait;

    // The trace context the integration injects into a message before it sets the checkpoint.
    const TRACE_HEADERS = [
        'x-datadog-trace-id' => '1311263442542712053',
        'x-datadog-parent-id' => '989385426358345137',
        'x-datadog-sampling-priority' => '1',
        'x-datadog-tags' => '_dd.p.dm=-0,_dd.p.tid=66f1a2b300000000',
        'traceparent' => '00-66f1a2b30000000011f71fb04cb4dad5-0dbc0f84a3c9d0b1-01',
        'tracestate' => 'dd=p:0dbc0f84a3c9d0b1;s:1;t.dm:-0;t.tid:66f1a2b300000000',
    ];

    /** @var AMQPMessage */
    private $published;

    /** @var AMQPMessage */
    private $delivered;

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp")
     * @Warmup(1)
     */
    public function benchPublishCheckpoint()
    {
        AMQPIntegration::setDataStreamsProduceCheckpoint($this->published, '', 'orders');
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
        AMQPIntegration::setDataStreamsConsumeCheckpoint($this->delivered, 'orders');
    }

    public function setUp()
    {
        $context = $this->enableDataStreams();

        $body = str_repeat('x', 512);
        $this->published = new AMQPMessage($body, [
            'application_headers' => new AMQPTable(self::TRACE_HEADERS),
        ]);
        $this->delivered = new AMQPMessage($body, [
            'application_headers' => new AMQPTable(self::TRACE_HEADERS + [
                AMQPIntegration::DSM_CONTEXT_HEADER => $context,
            ]),
        ]);
    }
}
