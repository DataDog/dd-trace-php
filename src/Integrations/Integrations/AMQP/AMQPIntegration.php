<?php

namespace DDTrace\Integrations\AMQP;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AbstractChannel;
use function DDTrace\hook_method;
use function DDTrace\trace_method;

class AMQPIntegration extends Integration
{
    const NAME = 'amqp';

    protected $protocolVersion;
    public $host;
    public $port;
    public $url;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function getUrl()
    {
        return "amqp://{$this->host}:{$this->port}";
    }

    public function setGenericTags(SpanData $span, $exception = null) {
        $span->service = 'amqp';

        $span->meta[Tag::COMPONENT] = 'amqp';
        $span->meta[Tag::MQ_SYSTEM] = 'rabbitmq';
        $span->meta[Tag::MQ_DESTINATION_KIND] = 'queue';
        $span->meta[Tag::MQ_PROTOCOL] = 'amqp';
        $span->meta[Tag::MQ_PROTOCOL_VERSION] = $this->protocolVersion;
        $span->meta[Tag::MQ_URL] = $this->getUrl();

        if ($exception) {
            $this->setError($span, $exception);
        }
    }

    /**
     * Add instrumentation to AMQP requests
     */
    public function init()
    {
        $integration = $this;
        $this->protocolVersion = AbstractChannel::getProtocolVersion();

        \DDTrace\hook_method(
            'PhpAmqpLib\Wire\IO\SocketIO',
            '__construct',
            function ($This, $scope, $args) use ($integration) {
                $integration->host = $args[0];
                $integration->port = $args[1];
            }
        );

        \DDTrace\hook_method(
            'PhpAmqpLib\Wire\IO\StreamIO',
            '__construct',
            function ($This, $scope, $args) use ($integration) {
                $integration->host = $args[0];
                $integration->port = $args[1];
            }
        );

        \DDTrace\trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_deliver",
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'amqp.basic.deliver';
                $span->type = Type::MESSAGE_CONSUMER;
                $span->meta[Tag::SPAN_KIND] = 'consumer';
                $integration->setGenericTags($span);

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
                $span->meta[Tag::MQ_DESTINATION] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $message->getConsumerTag();
                $span->meta['amqp.exchange'] = $exchangeDisplayName;

                try {
                    $span->meta[Tag::MQ_MESSAGE_ID] = $message->get('message_id');
                } catch (\Exception $e) {
                    // Ignore, it isn't set
                }

                try {
                    $span->meta[Tag::MQ_CONVERSATION_ID] = $message->get('correlation_id');
                } catch (\Exception $e) {
                    // Ignore, it isn't set
                }
            }
        );

        \DDTrace\trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_publish",
            function (SpanData $span, $args, $exception) use ($integration) {
                $span->name = 'amqp.basic.publish';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'producer';
                $integration->setGenericTags($span, $exception);

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
                $span->meta['amqp.exchange'] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->getBody());
                $span->meta[Tag::MQ_OPERATION] = 'send';
                // No consumer tag, since it is set from basic_deliver, and hence after basic_publish

                try {
                    $span->meta[Tag::MQ_MESSAGE_ID] = $message->get('message_id');
                } catch (\Exception $e) {
                    // Ignore, it isn't set
                }

                try {
                    $span->meta[Tag::MQ_CONVERSATION_ID] = $message->get('correlation_id');
                } catch (\Exception $e) {
                    // Ignore, it isn't set
                }

                //$serialized = $message->serialize_properties();
                //$span->meta['amqp.message_properties'] = print_r(\DDTrace\generate_distributed_tracing_headers(), true);
            }
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_consume",
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.basic.consume';
                $span->type = Type::MESSAGE_CONSUMER;
                $span->meta[Tag::SPAN_KIND] = 'consumer';
                $integration->setGenericTags($span, $exception);

                /** @var string $queue */
                $queue = $args[0];
                /** @var string $consumer_tag */
                $consumerTag = $args[1];

                $queueDisplayName = empty($queue) || !str_starts_with($queue, 'amq.gen-')
                    ? $queue : '<generated>';
                $span->resource = "basic.consume {$queueDisplayName}";
                $span->meta['amqp.queue'] = $queueDisplayName;
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $retval ?? $consumerTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'exchange_declare',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.exchange.declare';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $integration->setGenericTags($span, $exception);

                /** @var string $exchange */
                $exchange = $args[0];

                $exchangeDisplayName = empty($exchange) ? '<default>' : $exchange;
                $span->resource = "exchange.declare {$exchangeDisplayName}";
                $span->meta['amqp.exchange'] = $exchangeDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_declare',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.queue.declare';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $integration->setGenericTags($span, $exception);

                /** @var string $queue */
                $queue = $args[0];
                if (empty($queue) && is_array($retval)) {
                    list($queue, ,) = $retval;
                }

                $queueDisplayName = empty($queue) || !str_starts_with($queue, 'amq.gen-')
                    ? $queue : '<generated>';

                $span->resource = "queue.declare {$queueDisplayName}";
                $span->meta['amqp.queue'] = $queueDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_bind',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.queue.bind';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $integration->setGenericTags($span, $exception);

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
                $span->meta['amqp.queue'] = $queueDisplayName;
                $span->meta['amqp.exchange'] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
            }
        );

        // TODO: Double-check the span kind & operation for this one
        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_consume_ok',
            function (SpanData $span) use ($integration) {
                $span->name = 'amqp.basic.consume_ok';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'producer';
                $integration->setGenericTags($span);

                $span->resource = 'basic.consume_ok';
                $span->meta[Tag::MQ_OPERATION] = 'process';
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_cancel',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.basic.cancel';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $integration->setGenericTags($span, $exception);

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
                $span->name = 'amqp.basic.cancel_ok';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'producer';
                $integration->setGenericTags($span, $exception);

                $span->resource = 'basic.cancel_ok';
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'connect',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.connect';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $integration->setGenericTags($span, $exception);

                $span->resource = 'connect';
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'reconnect',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.reconnect';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $integration->setGenericTags($span, $exception);

                $span->resource = 'reconnect';
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_ack',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.basic.ack';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'process';
                $integration->setGenericTags($span, $exception);

                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                $span->resource = "basic.ack {$deliveryTag}";
                $span->meta['amqp.delivery_tag'] = $deliveryTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_nack',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $span->name = 'amqp.basic.nack';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'process';
                $integration->setGenericTags($span, $exception);

                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                $span->resource = "basic.nack {$deliveryTag}";
                $span->meta['amqp.delivery_tag'] = $deliveryTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_get',
            function (SpanData $span, $args, $message, $exception) use ($integration) {
                $span->name = 'amqp.basic.get';
                $span->type = Type::MESSAGE_PRODUCER;
                $span->meta[Tag::SPAN_KIND] = 'client';
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $integration->setGenericTags($span, $exception);

                /** @var string $queue */
                $queue = $args[0];

                $queueDisplayName = empty($queue) || !str_starts_with($queue, 'amq.gen-')
                    ? $queue : '<generated>';

                $span->resource = "basic.get {$queueDisplayName}";
                $span->meta['amqp.queue'] = $queueDisplayName;

                if (!is_null($message)) {
                    /** @var AMQPMessage $message */
                    $exchange = $message->getExchange();
                    $routingKey = $message->getRoutingKey();
                    $deliveryTag = $message->getDeliveryTag();

                    $exchangeDisplayName = empty($exchange) ? '<default>' : $exchange;
                    $routingKeyDisplayName = empty($routingKey)
                        ? '<all>'
                        : (str_starts_with($routingKey, 'amq.gen-')
                            ? '<generated>'
                            : $routingKey);

                    $span->meta['amqp.exchange'] = $exchangeDisplayName;
                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta['amqp.delivery_tag'] = $deliveryTag;

                    $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();

                    try {
                        $span->meta[Tag::MQ_MESSAGE_ID] = $message->get('message_id');
                    } catch (\Exception $e) {
                        // Ignore, it isn't set
                    }
                    try {
                        $span->meta[Tag::MQ_CONVERSATION_ID] = $message->get('correlation_id');
                    } catch (\Exception $e) {
                        // Ignore, it isn't set
                    }
                }
            }
        );

        return Integration::LOADED;
    }
}
