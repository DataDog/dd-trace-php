<?php

namespace DDTrace\Tests\Integrations\AMQP;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class AMQPTest extends IntegrationTestCase
{
    /**
     * @return AMQPStreamConnection
     */
    protected function connectionToServer()
    {
        return new AMQPStreamConnection('rabbitmq_integration', 5672, 'guest', 'guest');
    }

    public function testConnectError()
    {
        $traces = $this->isolateTracer(function () {
            try {
                $connection = new AMQPStreamConnection('rabbitmq_integration', 5673, 'guest', 'guest'); // wrong port
                $connection->close();
            } catch (\Exception $e) {
                // Ignore exception
            }
        });

        $this->assertSpans($traces, [
                SpanAssertion::build(
                    'amqp.connect',
                    'amqp',
                    'queue',
                    'connect'
                )->setError(
                    'PhpAmqpLib\Exception\AMQPIOException',
                    'Connection refused',
                    true
                )->withExactTags([
                    Tag::SPAN_KIND                  => 'client',
                    Tag::COMPONENT                  => 'amqp',
                    Tag::MQ_SYSTEM                  => 'rabbitmq',
                    Tag::MQ_DESTINATION_KIND        => 'queue',
                    Tag::MQ_PROTOCOL                => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                ])
            ]);
    }

    public function testHelloWorld()
    {
        $receivedMessage = false;
        // The simplest thing that does something
        $traces = $this->isolateTracer(function () use (&$receivedMessage) {
            $consumerConnection = $this->connectionToServer();
            $consumerChannel = $consumerConnection->channel();
            $consumerChannel->queue_declare('hello', false, false, false, false);
            $callback = function () use (&$receivedMessage) {
                $receivedMessage = true;
            };
            $consumerChannel->basic_consume('hello', '', false, true, false, false, $callback);

            $producerConnection = $this->connectionToServer();
            $producerChannel = $producerConnection->channel();
            $producerChannel->queue_declare('hello', false, false, false, false);
            $msg = new AMQPMessage('Hello World!');
            $producerChannel->basic_publish($msg, '', 'hello');

            // Wait for the consumer to receive the message, but no more than 5 seconds
            // Note: Blocking call
            $consumerChannel->wait(null, false, 5);

            $consumerChannel->close();
            $consumerConnection->close();

            $producerChannel->close();
            $producerConnection->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello',
            ]),
            SpanAssertion::build(
                'amqp.basic.consume',
                'amqp',
                'queue',
                'basic.consume hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_OPERATION               => 'receive',
                Tag::MQ_DESTINATION             => 'hello',
            ])->withExistingTagsNames([
                Tag::MQ_CONSUMER_ID
            ])->withChildren([
                SpanAssertion::build(
                    'amqp.basic.consume_ok',
                    'amqp',
                    'queue',
                    'basic.consume_ok'
                )->withExactTags([
                    Tag::SPAN_KIND                  => 'server',
                    Tag::COMPONENT                  => 'amqp',
                    Tag::MQ_SYSTEM                  => 'rabbitmq',
                    Tag::MQ_DESTINATION_KIND        => 'queue',
                    Tag::MQ_PROTOCOL                => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                    Tag::MQ_OPERATION               => 'process',
                ]),
            ]),
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello',
            ]),
            SpanAssertion::build(
                'amqp.basic.publish',
                'amqp',
                'queue',
                'basic.publish <default> -> hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'producer',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::RABBITMQ_ROUTING_KEY       => 'hello',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 12,
                Tag::MQ_OPERATION               => 'send',
                Tag::RABBITMQ_EXCHANGE          => '<default>',
            ])->withChildren([
                SpanAssertion::build(
                    'amqp.basic.deliver',
                    'amqp',
                    'queue',
                    'basic.deliver <default> -> hello'
                )->withExactTags([
                    Tag::SPAN_KIND                  => 'consumer',
                    Tag::COMPONENT                  => 'amqp',
                    Tag::MQ_SYSTEM                  => 'rabbitmq',
                    Tag::RABBITMQ_ROUTING_KEY       => 'hello',
                    Tag::MQ_DESTINATION_KIND        => 'queue',
                    Tag::MQ_PROTOCOL                => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                    Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 12,
                    Tag::MQ_OPERATION               => 'receive',
                    Tag::RABBITMQ_EXCHANGE          => '<default>',
                ])->withExistingTagsNames([
                    Tag::MQ_CONSUMER_ID,
                    '_dd.span_links'
                ])
            ]),
            SpanAssertion::build(
                'amqp.basic.deliver',
                'amqp',
                'queue',
                'basic.deliver <default> -> hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'consumer',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::RABBITMQ_ROUTING_KEY       => 'hello',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 12,
                Tag::MQ_OPERATION               => 'receive',
                Tag::RABBITMQ_EXCHANGE          => '<default>',
            ])->withExistingTagsNames([
                Tag::MQ_CONSUMER_ID,
                '_dd.span_links'
            ])
        ]);

        $this->assertTrue($receivedMessage);
    }

    public function testRouting()
    {
        // Receiving messages selectively
        // @see https://www.rabbitmq.com/tutorials/tutorial-four-php.html
        $receivedMessage = false;
        $traces = $this->isolateTracer(function () use (&$receivedMessage) {
            $receiveLogsConnection = $this->connectionToServer();
            $receiveLogsChannel = $receiveLogsConnection->channel();
            $receiveLogsChannel->exchange_declare('direct_logs', 'direct', false, false, false);
            list($receiveLogsQueueName, ,) = $receiveLogsChannel->queue_declare("", false, false, true, false);

            $severities = ['info', 'warning', 'error'];

            foreach ($severities as $severity) {
                $receiveLogsChannel->queue_bind($receiveLogsQueueName, 'direct_logs', $severity);
            }

            $callback = function () use (&$receivedMessage) {
                $receivedMessage = true;
            };

            $receiveLogsChannel->basic_consume($receiveLogsQueueName, '', false, true, false, false, $callback);

            $sendLogConnection = $this->connectionToServer();
            $sendLogChannel = $sendLogConnection->channel();
            $sendLogChannel->exchange_declare('direct_logs', 'direct', false, false, false);

            $severity = 'error';
            $data = 'Run. Run. Or it will explode.';
            $msg = new AMQPMessage($data);
            $sendLogChannel->basic_publish($msg, 'direct_logs', $severity);

            // Wait for the consumer to receive the message, but no more than 5 seconds
            // Note: Blocking call
            $receiveLogsChannel->wait(null, false, 5);

            $receiveLogsChannel->close();
            $receiveLogsConnection->close();

            $sendLogChannel->close();
            $sendLogConnection->close();
        });


        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.exchange.declare',
                'amqp',
                'queue',
                'exchange.declare direct_logs'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare <generated>'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => '<generated>',
            ]),
            SpanAssertion::build(
                'amqp.queue.bind',
                'amqp',
                'queue',
                'queue.bind <generated> direct_logs -> info'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => '<generated>',
                Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
                Tag::RABBITMQ_ROUTING_KEY       => 'info',
            ]),
            SpanAssertion::build(
                'amqp.queue.bind',
                'amqp',
                'queue',
                'queue.bind <generated> direct_logs -> warning'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => '<generated>',
                Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
                Tag::RABBITMQ_ROUTING_KEY       => 'warning',
            ]),
            SpanAssertion::build(
                'amqp.queue.bind',
                'amqp',
                'queue',
                'queue.bind <generated> direct_logs -> error'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => '<generated>',
                Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
                Tag::RABBITMQ_ROUTING_KEY       => 'error',
            ]),
            SpanAssertion::build(
                'amqp.basic.consume',
                'amqp',
                'queue',
                'basic.consume <generated>'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => '<generated>',
                Tag::MQ_OPERATION               => 'receive',
            ])->withExistingTagsNames([
                Tag::MQ_CONSUMER_ID
            ])->withChildren([
                SpanAssertion::build(
                    'amqp.basic.consume_ok',
                    'amqp',
                    'queue',
                    'basic.consume_ok'
                )->withExactTags([
                    Tag::SPAN_KIND              => 'server',
                    Tag::COMPONENT              => 'amqp',
                    Tag::MQ_SYSTEM              => 'rabbitmq',
                    Tag::MQ_DESTINATION_KIND    => 'queue',
                    Tag::MQ_PROTOCOL            => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION    => AMQPChannel::getProtocolVersion(),
                    Tag::MQ_OPERATION           => 'process',
                ])
            ]),
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.exchange.declare',
                'amqp',
                'queue',
                'exchange.declare direct_logs'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
            ]),
            SpanAssertion::build(
                'amqp.basic.publish',
                'amqp',
                'queue',
                'basic.publish direct_logs -> error'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'producer',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
                Tag::RABBITMQ_ROUTING_KEY       => 'error',
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 29,
                Tag::MQ_OPERATION               => 'send',
            ])->withChildren([
                SpanAssertion::build(
                    'amqp.basic.deliver',
                    'amqp',
                    'queue',
                    'basic.deliver direct_logs -> error'
                )->withExactTags([
                    Tag::SPAN_KIND                  => 'consumer',
                    Tag::COMPONENT                  => 'amqp',
                    Tag::MQ_SYSTEM                  => 'rabbitmq',
                    Tag::MQ_DESTINATION_KIND        => 'queue',
                    Tag::MQ_PROTOCOL                => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                    Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
                    Tag::RABBITMQ_ROUTING_KEY       => 'error',
                    Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 29,
                    Tag::MQ_OPERATION               => 'receive',
                ])->withExistingTagsNames([
                    Tag::MQ_CONSUMER_ID,
                    '_dd.span_links'
                ])
            ]),
            SpanAssertion::build(
                'amqp.basic.deliver',
                'amqp',
                'queue',
                'basic.deliver direct_logs -> error'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'consumer',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::RABBITMQ_EXCHANGE          => 'direct_logs',
                Tag::RABBITMQ_ROUTING_KEY       => 'error',
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 29,
                Tag::MQ_OPERATION               => 'receive',
            ])->withExistingTagsNames([
                Tag::MQ_CONSUMER_ID,
                '_dd.span_links'
            ])
        ]);

        $this->assertTrue($receivedMessage);
    }

    public function testCancel()
    {
        $traces = $this->isolateTracer(function () {
            $connection = $this->connectionToServer();
            $channel = $connection->channel();
            $channel->queue_declare('hello', false, false, false, false);

            $callback = function ($msg) {
                echo ' [x] ', $msg->body, "\n";
            };

            $consumerTag = $channel->basic_consume('hello', '', false, true, false, false, $callback);

            $channel->basic_cancel($consumerTag);

            $channel->close();
            $connection->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello',
            ]),
            SpanAssertion::build(
                'amqp.basic.consume',
                'amqp',
                'queue',
                'basic.consume hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello',
                Tag::MQ_OPERATION               => 'receive',
            ])->withExistingTagsNames([
                Tag::MQ_CONSUMER_ID
            ])->withChildren([
                SpanAssertion::build(
                    'amqp.basic.consume_ok',
                    'amqp',
                    'queue',
                    'basic.consume_ok'
                )->withExactTags([
                    Tag::SPAN_KIND              => 'server',
                    Tag::COMPONENT              => 'amqp',
                    Tag::MQ_SYSTEM              => 'rabbitmq',
                    Tag::MQ_DESTINATION_KIND    => 'queue',
                    Tag::MQ_PROTOCOL            => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION    => AMQPChannel::getProtocolVersion(),
                    Tag::MQ_OPERATION           => 'process',
                ])
            ]),
            SpanAssertion::exists('amqp.basic.cancel')->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello',
            ])->withChildren([
                SpanAssertion::build(
                    'amqp.basic.cancel_ok',
                    'amqp',
                    'queue',
                    'basic.cancel_ok'
                )->withExactTags([
                    Tag::SPAN_KIND              => 'server',
                    Tag::COMPONENT              => 'amqp',
                    Tag::MQ_SYSTEM              => 'rabbitmq',
                    Tag::MQ_DESTINATION_KIND    => 'queue',
                    Tag::MQ_PROTOCOL            => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION    => AMQPChannel::getProtocolVersion(),
                ])
            ]),
        ]);
    }

    public function testPublishOnClosedChannel()
    {
        $traces = $this->isolateTracer(function () {
            $connection = $this->connectionToServer();
            $channel = $connection->channel();
            $channel->queue_declare('hello', false, false, false, false);

            $channel->close();

            try {
                // This WILL throw an exception, and it MUST be captured in the span
                $channel->basic_publish(new AMQPMessage('Hello World!'), '', 'hello');
            } catch (\Exception $e) {
                // Do nothing
            }

            $connection->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello',
            ]),
            SpanAssertion::build(
                'amqp.basic.publish',
                'amqp',
                'queue',
                'basic.publish <default> -> hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'producer',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::RABBITMQ_ROUTING_KEY       => 'hello',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 12,
                Tag::MQ_OPERATION               => 'send',
                Tag::RABBITMQ_EXCHANGE          => '<default>',
            ])->setError(
                'PhpAmqpLib\Exception\AMQPChannelClosedException',
                'Channel connection is closed'
            )->withExistingTagsNames([
                Tag::ERROR_STACK
            ])
        ]);
    }

    public function testReconnect()
    {
        $traces = $this->isolateTracer(function () {
            $connection = $this->connectionToServer();
            $channel = $connection->channel();
            $channel->queue_declare('hello', false, false, false, false);

            $connection->reconnect();

            $channel->close();
            $connection->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare hello'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello',
            ]),
            SpanAssertion::build(
                'amqp.reconnect',
                'amqp',
                'queue',
                'reconnect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion()
            ])->withChildren([
                SpanAssertion::build(
                    'amqp.connect',
                    'amqp',
                    'queue',
                    'connect'
                )->withExactTags([
                    Tag::SPAN_KIND                  => 'client',
                    Tag::COMPONENT                  => 'amqp',
                    Tag::MQ_SYSTEM                  => 'rabbitmq',
                    Tag::MQ_DESTINATION_KIND        => 'queue',
                    Tag::MQ_PROTOCOL                => 'AMQP',
                    Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                ])
            ])
        ]);
    }

    public function testBasicGet()
    {
        $traces = $this->isolateTracer(function () {
            $exchange = 'basic_get_test';
            $queue = 'basic_get_queue';

            $connection = $this->connectionToServer();
            $channel = $connection->channel();
            $channel->queue_declare($queue, false, true, false, false);
            $channel->exchange_declare($exchange, 'direct', false, true, false);
            $channel->queue_bind($queue, $exchange);

            $toSend = new AMQPMessage('test message', array('content_type' => 'text/plain', 'delivery_mode' => 2));
            $channel->basic_publish($toSend, $exchange);

            $message = $channel->basic_get($queue);
            $message->ack();

            $channel->close();
            $connection->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'amqp.connect',
                'amqp',
                'queue',
                'connect'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion()
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare basic_get_queue'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'basic_get_queue'
            ]),
            SpanAssertion::build(
                'amqp.exchange.declare',
                'amqp',
                'queue',
                'exchange.declare basic_get_test'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::RABBITMQ_EXCHANGE          => 'basic_get_test'
            ]),
            SpanAssertion::build(
                'amqp.queue.bind',
                'amqp',
                'queue',
                'queue.bind basic_get_queue basic_get_test -> <all>'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'basic_get_queue',
                Tag::RABBITMQ_EXCHANGE          => 'basic_get_test',
                Tag::RABBITMQ_ROUTING_KEY       => '<all>'
            ]),
            SpanAssertion::build(
                'amqp.basic.publish',
                'amqp',
                'queue',
                'basic.publish basic_get_test -> <all>'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'producer',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 12,
                Tag::MQ_OPERATION               => 'send',
                Tag::RABBITMQ_ROUTING_KEY       => '<all>',
                Tag::RABBITMQ_EXCHANGE          => 'basic_get_test',
                Tag::RABBITMQ_DELIVERY_MODE     => '2'
            ]),
            SpanAssertion::build(
                'amqp.basic.get',
                'amqp',
                'queue',
                'basic.get basic_get_queue'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'consumer',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'basic_get_queue',
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 12,
                Tag::MQ_OPERATION               => 'receive',
                Tag::RABBITMQ_ROUTING_KEY       => '<all>',
                Tag::RABBITMQ_EXCHANGE          => 'basic_get_test',
                Tag::RABBITMQ_DELIVERY_MODE     => '2'
            ])->withExistingTagsNames([
                '_dd.span_links'
            ]),
            SpanAssertion::build(
                'amqp.basic.ack',
                'amqp',
                'queue',
                'basic.ack 1'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'process',
                Tag::COMPONENT                  => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion()
            ]),
        ]);
    }

    public function testDistributedTracing()
    {
        // Note: This test is extremely flaky, locally at least. It will eventually pass with some tries...
        // Reason: We may parse the traces from dumped data BEFORE the traces are flushed.

        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42'); // Not necessary, but makes it easier to debug locally

        $sendTraces = $this->inCli(
            __DIR__ . '/scripts/send.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
            ]
        );

        list($receiveTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/receive.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_CLI_ENABLED' => 'true',
            ],
            [],
            '',
            true
        );

        // Assess that user headers weren't lost
        $this->assertSame("", $output);

        $sendTraces = $sendTraces[0][0]; // There is a root span
        // Spans: send.php -> basic_publish -> queue_declare -> connect
        $basicPublishSpan = $sendTraces[1];

        $receiveTraces = $receiveTraces[3]; // There isn't a root span
        // Spans: connect -> queue_declare -> basic_consume & basic_consume_ok -> basic_deliver
        $basicDeliverSpan = $receiveTraces[0];

        $this->assertSame($basicPublishSpan['trace_id'], $basicDeliverSpan['trace_id']);
        $this->assertSame($basicPublishSpan['span_id'], $basicDeliverSpan['parent_id']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42'); // Not necessary, but makes it easier to debug locally

        $sendTraces = $this->inCli(
            __DIR__ . '/scripts/send.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_DISTRIBUTED_TRACING' => 'false'
            ]
        );

        list($receiveTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/receive.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_CLI_ENABLED' => 'true'
            ],
            [],
            '',
            true
        );

        // Assess that user headers weren't lost
        $this->assertSame("", $output);

        $sendTraces = $sendTraces[0][0]; // There is a root span
        // Spans: send.php -> basic_publish -> queue_declare -> connect
        $basicPublishSpan = $sendTraces[1];

        $receiveTraces = $receiveTraces[3]; // There isn't a root span
        // Spans: connect -> queue_declare -> basic_consume & basic_consume_ok -> basic_deliver
        $basicDeliverSpan = $receiveTraces[0];

        $this->assertNotSame($basicPublishSpan['trace_id'], $basicDeliverSpan['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $basicDeliverSpan);
    }
}
