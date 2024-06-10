<?php

namespace DDTrace\Integrations\PHPRdKafka;

use DDTrace\Integrations\Integration;
use DDTrace\Propagator;
use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\Tag;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\hook_method;
use function DDTrace\start_trace_span;
use function DDTrace\trace_method;

class RdKafkaIntegration extends Integration
{
    const NAME = 'kafka';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to Kafka requests
     */
    public function init()
    {
        $integration = $this;
        trace_method(
            "RdKafka\ProducerTopic",
            "producev",
            [
                'prehook' => function (SpanData $span, $args) use ($integration, &$newTrace) {
                    $integration->setGenericTags(
                        $span,
                        'producev',
                        'client'
                    );
                }
            ]
        );

        trace_method(
            "RdKafka\ProducerTopic",
            "produce",
            [
                'prehook' => function (SpanData $span, $args) use ($integration, &$newTrace) {
                    $integration->setGenericTags(
                        $span,
                        'produce',
                        'client'
                    );
                }
            ]
        );

        trace_method(
            "RdKafka\ConsumerTopic",
            "Consume",
            [
                'prehook' => function (SpanData $span, $args) use ($integration, &$newTrace) {
                    $integration->setGenericTags(
                        $span,
                        'consume',
                        'client'
                    );
                }
            ]
        );

        return Integration::LOADED;
    }
    
    public function setGenericTags(
        SpanData $span,
        string $name,
        string $spanKind
    ) {
        $span->name = "kafka.$name";
        $span->resource = "$name";
        $span->meta[Tag::SPAN_KIND] = $spanKind;
        $span->type = 'queue';
        $span->service = 'kafka';
        $span->meta[Tag::COMPONENT] = RdKafkaIntegration::NAME;
    }
}
