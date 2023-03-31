<?php

namespace DDTrace\Integrations\AMQP;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

use function DDTrace\hook_method;
use function DDTrace\trace_method;

class AMQPIntegration extends Integration
{
    const NAME = 'amqp';
    const SYSTEM = 'rabbitmq';
    protected $protocolVersion;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to AMQP requests
     */
    public function init()
    {
        $integration = $this;
        $this->protocolVersion = "";

        hook_method(
            'PhpAmqpLib\Channel\AbstractChannel',
            '__construct',
            null,
            function ($This) use ($integration) {
                $integration->protocolVersion = $This::getProtocolVersion();
            }
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_deliver",
            function (SpanData $span, $args) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.deliver', 'consumer');

                /** @var AMQPMessage $message */
                $message = $args[1];

                $exchange = $message->getExchange();
                $routingKey = $message->getRoutingKey();

                $exchangeDisplayName = empty($exchange) ? '<default>' : $exchange;
                $routingKeyDisplayName = empty($routingKey)
                    ? '<all>'
                    : (str_starts_with($routingKey, 'amq.gen-')
                        ? '<generated>'
                        : $routingKey);

                $span->resource = "basic.deliver {$exchangeDisplayName} -> {$routingKeyDisplayName}";
                $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $message->getConsumerTag();

                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;

                $integration->setOptionalMessageTags($span, $message);

                // Try to extract propagated context values from headers
                $integration->extract($message);
            }
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_publish",
            [
                'prehook' => function (SpanData $span, $args) use ($integration) {
                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    $integration->inject($message);
                },
                'posthook' => function (SpanData $span, $args, $exception) use ($integration) {
                    $integration->setGenericTags($span, 'amqp.basic.publish', 'producer', $exception);

                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    /** @var string $exchange */
                    $exchange = $args[1];
                    /** @var string $routing_key */
                    $routingKey = $args[2] ?? '';

                    $exchangeDisplayName = empty($exchange) ? '<default>' : $exchange;
                    $routingKeyDisplayName = empty($routingKey)
                        ? '<all>'
                        : (str_starts_with($routingKey, 'amq.gen-')
                            ? '<generated>'
                            : $routingKey);

                    $span->resource = "basic.publish {$exchangeDisplayName} -> {$routingKeyDisplayName}";
                    $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->getBody());
                    $span->meta[Tag::MQ_OPERATION] = 'send';

                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;

                    $integration->setOptionalMessageTags($span, $message);
                }
            ]
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_consume",
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.consume', 'client', $exception);

                /** @var string $queue */
                $queue = $args[0];
                /** @var string $consumer_tag */
                $consumerTag = $args[1];

                $queueDisplayName = empty($queue) || !str_starts_with($queue, 'amq.gen-')
                    ? $queue : '<generated>';

                $span->resource = "basic.consume {$queueDisplayName}";
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $retval ?? $consumerTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'exchange_declare',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.exchange.declare', 'client', $exception);

                /** @var string $exchange */
                $exchange = $args[0];

                $exchangeDisplayName = empty($exchange) ? '<default>' : $exchange;

                $span->resource = "exchange.declare {$exchangeDisplayName}";
                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_declare',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.queue.declare', 'client', $exception);

                /** @var string $queue */
                $queue = $args[0];
                if (empty($queue) && is_array($retval)) {
                    list($queue, ,) = $retval;
                }

                $queueDisplayName = empty($queue) || !str_starts_with($queue, 'amq.gen-')
                    ? $queue : '<generated>';

                $span->resource = "queue.declare {$queueDisplayName}";
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_bind',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.queue.bind', 'client', $exception);

                /** @var string $queue */
                $queue = $args[0];
                /** @var string $exchange */
                $exchange = $args[1];
                /** @var string $routingKey */
                $routingKey = $args[2] ?? '';

                $queueDisplayName = empty($queue) || !str_starts_with($queue, 'amq.gen-')
                    ? $queue : '<generated>';
                $exchangeDisplayName = empty($exchange) ? '<default>' : $exchange;
                $routingKeyDisplayName = empty($routingKey)
                    ? '<all>'
                    : (str_starts_with($routingKey, 'amq.gen-')
                        ? '<generated>'
                        : $routingKey);

                $span->resource = "queue.bind {$queueDisplayName} {$exchangeDisplayName} -> {$routingKeyDisplayName}";
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;

                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
            }
        );

        // TODO: Double-check the span kind & operation for this one
        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_consume_ok',
            function (SpanData $span) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.consume_ok', 'server');

                $span->resource = 'basic.consume_ok';
                $span->meta[Tag::MQ_OPERATION] = 'process';
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_cancel',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.cancel', 'client', $exception);

                /** @var string $consumerTag */
                $consumerTag = $args[0];

                $span->resource = "basic.cancel {$consumerTag}";
                $span->meta[Tag::MQ_CONSUMER_ID] = $consumerTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_cancel_ok',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.cancel_ok', 'server', $exception);
                $span->resource = 'basic.cancel_ok';
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'connect',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.connect', 'client', $exception);
                $span->resource = 'connect';
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'reconnect',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.reconnect', 'client', $exception);
                $span->resource = 'reconnect';
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_ack',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.ack', 'process', $exception);

                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                $span->resource = "basic.ack $deliveryTag";
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_nack',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.nack', 'process', $exception);

                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                $span->resource = "basic.nack $deliveryTag";
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_get',
            function (SpanData $span, $args, $message, $exception) use ($integration) {
                $integration->setGenericTags($span, 'amqp.basic.get', 'consumer', $exception);

                /** @var string $queue */
                $queue = $args[0];

                $queueDisplayName = empty($queue) || !str_starts_with($queue, 'amq.gen-')
                    ? $queue : '<generated>';

                $span->resource = "basic.get $queueDisplayName";
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;

                if (!is_null($message)) {
                    /** @var AMQPMessage $message */
                    $exchange = $message->getExchange();
                    $routingKey = $message->getRoutingKey();

                    $exchangeDisplayName = empty($exchange) ? '<default>' : $exchange;
                    $routingKeyDisplayName = empty($routingKey)
                        ? '<all>'
                        : (str_starts_with($routingKey, 'amq.gen-')
                            ? '<generated>'
                            : $routingKey);

                    $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();

                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;

                    $integration->setOptionalMessageTags($span, $message);

                    // Try to extract propagated context values from headers
                    $integration->extract($message);
                }
            }
        );

        return Integration::LOADED;
    }

    public function setGenericTags(SpanData $span, string $name, string $spanKind, $exception = null) {
        $span->name = $name;
        $span->meta[Tag::SPAN_KIND] = $spanKind;
        $span->type = 'queue';
        $span->service = 'amqp';
        $span->meta[Tag::COMPONENT] = AMQPIntegration::NAME;

        $span->meta[Tag::MQ_SYSTEM] = AMQPIntegration::SYSTEM;
        $span->meta[Tag::MQ_DESTINATION_KIND] = 'queue';
        $span->meta[Tag::MQ_PROTOCOL] = 'AMQP';
        $span->meta[Tag::MQ_PROTOCOL_VERSION] = $this->protocolVersion;

        if ($exception) {
            $this->setError($span, $exception);
        }
    }

    public function setOptionalMessageTags(SpanData $span, AMQPMessage $message) {
        if ($message->has('delivery_mode')) {
            $span->meta[Tag::RABBITMQ_DELIVERY_MODE] = $message->get('delivery_mode');
        }
        if ($message->has('message_id')) {
            $span->meta[Tag::MQ_MESSAGE_ID] = $message->get('message_id');
        }
        if ($message->has('correlation_id')) {
            $span->meta[Tag::MQ_CONVERSATION_ID] = $message->get('correlation_id');
        }
    }

    public function inject(AMQPMessage $message)
    {
        $distributedHeaders = \DDTrace\generate_distributed_tracing_headers();
        if ($message->has('application_headers')) {
            // If the message already has application headers, we need to merge them so user headers are not overwritten
            /** @var AMQPTable $headersObj */
            $headersObj = $message->get('application_headers');
            $headers = $headersObj->getNativeData();
            $headers = array_merge($headers, $distributedHeaders);
            $newHeaders = new AMQPTable($headers);
        } else {
            $newHeaders = new AMQPTable($distributedHeaders);
        }
        $message->set('application_headers', $newHeaders);
    }

    public function extract(AMQPMessage $message)
    {
        if ($message->has('application_headers')) {
            $headers = $message->get('application_headers');
            $headers = $headers->getNativeData();

            // If present, set the traceid of the current span to x-datadog-trace-id
            if (isset($headers['x-datadog-trace-id'])) {
                $traceId = $headers['x-datadog-trace-id'];
            } else {
                return;
            }

            // If present, set the spanid of the current span to x-datadog-parent-id
            if (isset($headers['x-datadog-parent-id'])) {
                $parentId = $headers['x-datadog-parent-id'];
            } else {
                return;
            }

            // If present, set the sampling priority of the current span to x-datadog-sampling-priority
            $priority = $headers['x-datadog-sampling-priority'] ?? null;
            \DDTrace\set_priority_sampling($priority);

            // If present, set the origin of the current span to x-datadog-origin
            $origin = $headers['x-datadog-origin'] ?? null;

            \DDTrace\set_distributed_tracing_context($traceId, $parentId, $origin);
        }
    }

}
