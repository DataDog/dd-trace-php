<?php

namespace DDTrace\Integrations\AMQP;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use PhpAmqpLib\Message\AMQPMessage;
use function DDTrace\hook_method;
use function DDTrace\trace_method;

class AMQPIntegration extends Integration
{
    const NAME = 'amqp';

    private $protocolVersion;

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

        \DDTrace\hook_method(
            "PhpAmqpLib\Connection\AbstractChannel",
            "__construct",
            null,
            function ($This) use ($integration) {
                $integration->protocolVersion = $This->getProtocolVersion();
            }
        );

        \DDTrace\trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_deliver",
            function (SpanData $span, $args) {
                $span->name = 'amqp.basic.deliver';
                $span->type = 'amqp';
                $span->service = 'amqp';

                /** @var AMQPMessage $message */
                $message = $args[1];

                $span->meta[Tag::MQ_SYSTEM] = 'rabbitmq';
                if (strlen($message->getExchange()) > 0) {
                    $span->meta[Tag::MQ_DESTINATION] = $message->getExchange();
                    $span->meta[Tag::MQ_DESTINATION_KIND] = 'exchange';
                } else {
                    $span->meta[Tag::MQ_DESTINATION_KIND] = 'queue';
                }
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $message->getRoutingKey();
                $span->meta[Tag::MQ_PROTOCOL] = 'amqp';
                $span->meta[Tag::MQ_PROTOCOL_VERSION] = $this->protocolVersion;
                $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $message->getConsumerTag();

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
            function (SpanData $span, $args) {
                $span->name = 'amqp.basic.publish';
                $span->type = 'amqp';
                $span->service = 'amqp';

                /** @var AMQPMessage $message */
                $message = $args[0];
                /** @var string $exchange */
                $exchange = $args[1];
                /** @var string $routing_key */
                $routing_key = $args[2];

                $span->meta[Tag::MQ_SYSTEM] = 'rabbitmq';
                if (strlen($exchange) > 0) {
                    $span->meta[Tag::MQ_DESTINATION] = $exchange;
                    $span->meta[Tag::MQ_DESTINATION_KIND] = 'exchange';
                } else {
                    $span->meta[Tag::MQ_DESTINATION_KIND] = 'queue';
                }
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routing_key;
                $span->meta[Tag::MQ_PROTOCOL] = 'amqp';
                $span->meta[Tag::MQ_PROTOCOL_VERSION] = $this->protocolVersion;
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
            }
        );

        return Integration::LOADED;
    }
}
