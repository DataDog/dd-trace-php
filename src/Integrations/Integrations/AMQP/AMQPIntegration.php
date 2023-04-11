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
                /** @var AMQPMessage $message */
                $message = $args[1];

                $exchangeDisplayName = $integration->formatExchangeName($message->getExchange());
                $routingKeyDisplayName = $integration->formatRoutingKey($message->getRoutingKey());

                $integration->setGenericTags(
                    $span,
                    'basic.deliver',
                    'consumer',
                    "$exchangeDisplayName -> $routingKeyDisplayName"
                );
                $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $message->getConsumerTag();

                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;

                $integration->setOptionalMessageTags($span, $message);

                // Try to extract propagated context values from headers
                $integration->extractContext($message);
            }
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_publish",
            [
                'prehook' => function (SpanData $span, $args) use ($integration) {
                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    if (!is_null($message)) {
                        $integration->injectContext($message);
                    }
                },
                'posthook' => function (SpanData $span, $args, $exception) use ($integration) {
                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    /** @var string $exchange */
                    $exchange = $args[1];
                    /** @var string $routing_key */
                    $routingKey = $args[2] ?? '';

                    $exchangeDisplayName = $integration->formatExchangeName($exchange);
                    $routingKeyDisplayName = $integration->formatRoutingKey($routingKey);

                    $integration->setGenericTags(
                        $span,
                        'basic.publish',
                        'producer',
                        "$exchangeDisplayName -> $routingKeyDisplayName",
                        $exception);
                    $span->meta[Tag::MQ_OPERATION] = 'send';

                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;

                    if (!is_null($message)) {
                        $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->getBody());
                        $integration->setOptionalMessageTags($span, $message);
                    }
                }
            ]
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_consume",
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                /** @var string $queue */
                $queue = $args[0];
                /** @var string $consumer_tag */
                $consumerTag = $args[1];

                $queueDisplayName = $integration->formatQueueName($queue);

                $integration->setGenericTags(
                    $span,
                    'basic.consume',
                    'client',
                    $queueDisplayName,
                    $exception
                );
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $retval ?? $consumerTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'exchange_declare',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                /** @var string $exchange */
                $exchange = $args[0];

                $exchangeDisplayName = $integration->formatExchangeName($exchange);

                $integration->setGenericTags(
                    $span,
                    'exchange.declare',
                    'client',
                    $exchangeDisplayName,
                    $exception
                );
                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_declare',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                /** @var string $queue */
                $queue = $args[0];
                if (empty($queue) && is_array($retval)) {
                    list($queue, ,) = $retval;
                }

                $queueDisplayName = $integration->formatQueueName($queue);

                $integration->setGenericTags(
                    $span,
                    'queue.declare',
                    'client',
                    $queueDisplayName,
                    $exception
                );
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_bind',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {

                /** @var string $queue */
                $queue = $args[0];
                /** @var string $exchange */
                $exchange = $args[1];
                /** @var string $routingKey */
                $routingKey = $args[2] ?? '';

                $queueDisplayName = $integration->formatQueueName($queue);
                $exchangeDisplayName = $integration->formatExchangeName($exchange);
                $routingKeyDisplayName = $integration->formatRoutingKey($routingKey);

                $integration->setGenericTags(
                    $span,
                    'queue.bind',
                    'client',
                    "$queueDisplayName $exchangeDisplayName -> $routingKeyDisplayName",
                    $exception
                );
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;

                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_consume_ok',
            function (SpanData $span) use ($integration) {
                $integration->setGenericTags($span, 'basic.consume_ok', 'server');

                $span->meta[Tag::MQ_OPERATION] = 'process';
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_cancel',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                /** @var string $consumerTag */
                $consumerTag = $args[0];

                $integration->setGenericTags(
                    $span,
                    'basic.cancel',
                    'client',
                    $consumerTag,
                    $exception
                );
                $span->meta[Tag::MQ_CONSUMER_ID] = $consumerTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_cancel_ok',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'basic.cancel_ok', 'server', $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'connect',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'connect', 'client', $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'reconnect',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setGenericTags($span, 'reconnect', 'client', $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_ack',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                $integration->setGenericTags($span, 'basic.ack', 'process', $deliveryTag, $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_nack',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                $integration->setGenericTags($span, 'basic.nack', 'process',$deliveryTag, $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_get',
            function (SpanData $span, $args, $message, $exception) use ($integration) {
                /** @var string $queue */
                $queue = $args[0];

                $queueDisplayName = $integration->formatQueueName($queue);

                $integration->setGenericTags(
                    $span,
                    'basic.get',
                    'consumer',
                    $queueDisplayName,
                    $exception
                );
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;

                if (!is_null($message)) {
                    /** @var AMQPMessage $message */
                    $exchange = $message->getExchange();
                    $routingKey = $message->getRoutingKey();

                    $exchangeDisplayName = $this->formatExchangeName($exchange);
                    $routingKeyDisplayName = $this->formatRoutingKey($routingKey);

                    $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();

                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;

                    $integration->setOptionalMessageTags($span, $message);

                    // Try to extract propagated context values from headers
                    $integration->extractContext($message);
                }
            }
        );

        return Integration::LOADED;
    }

    public function formatQueueName($queue)
    {
        return empty($queue) || !str_starts_with($queue, 'amq.gen-')
            ? $queue
            : '<generated>';
    }

    public function formatExchangeName($exchange)
    {
        return empty($exchange) ? '<default>' : $exchange;
    }

    public function formatRoutingKey($routingKey)
    {
        return empty($routingKey)
            ? '<all>'
            : $this->formatQueueName($routingKey);
    }

    public function setGenericTags(
        SpanData $span,
        string $name,
        string $spanKind,
        string $resourceDetail = null,
        $exception = null
    ) {
        $span->name = "amqp.$name";
        $span->resource = "$name" . ($resourceDetail === null ? "" : " $resourceDetail");
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

    public function setOptionalMessageTags(SpanData $span, AMQPMessage $message)
    {
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

    public function injectContext(AMQPMessage $message)
    {
        if (\ddtrace_config_distributed_tracing_enabled() === false) {
            return;
        }

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

    public function extractContext(AMQPMessage $message)
    {
        if ($message->has('application_headers')) {
            $headers = $message->get('application_headers');
            $headers = $headers->getNativeData();

            \DDTrace\consume_distributed_tracing_headers($headers);
        }
    }
}
