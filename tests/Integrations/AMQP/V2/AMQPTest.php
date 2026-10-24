<?php

namespace DDTrace\Tests\Integrations\AMQP\V2;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class AMQPTest extends IntegrationTestCase
{
    protected static $lockedResource = "amqp";

    /**
     * @return AMQPStreamConnection
     */
    protected function connectionToServer()
    {
        return new AMQPStreamConnection('rabbitmq-integration', 5672, 'guest', 'guest');
    }

    public function testConnectError()
    {
        $traces = $this->isolateTracer(function () {
            try {
                $connection = new AMQPStreamConnection('rabbitmq-integration', 5673, 'guest', 'guest'); // wrong port
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
                    '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                    '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                    '_dd.svc_src' => 'amqp',
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
                    '_dd.span_links',
                    '_dd.p.tid'
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                    '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                    '_dd.svc_src' => 'amqp',
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
                    '_dd.span_links',
                    '_dd.p.tid'
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                    '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                    '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                    '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
                '_dd.svc_src' => 'amqp',
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
            __DIR__ . '/../scripts/send.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
            ],
            [],
            self::$autoloadPath
        );

        list($receiveTraces, $output) = $this->inCli(
            __DIR__ . '/../scripts/receive.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_CLI_ENABLED' => 'true',
            ],
            [],
            self::$autoloadPath,
            true
        );

        // Assess that user headers weren't lost
        $this->assertSame("", trim(preg_replace("(.*\[ddtrace].*)", "", $output)));

        $sendTraces = $sendTraces[0][0]; // There is a root span
        // Spans: send.php -> basic_publish -> queue_declare -> connect
        $basicPublishSpan = $sendTraces[1];

        foreach ($receiveTraces as $receiveTrace) {
            // Spans: connect -> queue_declare -> basic_consume & basic_consume_ok -> basic_deliver
            if ($receiveTrace[0]["name"] == "amqp.basic.deliver" && isset($receiveTrace[0]["parent_id"])) {
                $basicDeliverSpan = $receiveTrace[0];
                break;
            }
        }

        $this->assertSame($basicPublishSpan['trace_id'], $basicDeliverSpan['trace_id']);
        $this->assertSame($basicPublishSpan['span_id'], $basicDeliverSpan['parent_id']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42'); // Not necessary, but makes it easier to debug locally

        $sendTraces = $this->inCli(
            __DIR__ . '/../scripts/send.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_DISTRIBUTED_TRACING' => 'false'
            ],
            [],
            self::$autoloadPath
        );

        list($receiveTraces, $output) = $this->inCli(
            __DIR__ . '/../scripts/receive.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_CLI_ENABLED' => 'true'
            ],
            [],
            self::$autoloadPath,
            true
        );

        // Assess that user headers weren't lost
        $this->assertSame("", trim(preg_replace("(.*\[ddtrace].*)", "", $output)));

        $sendTraces = $sendTraces[0][0]; // There is a root span
        // Spans: send.php -> basic_publish -> queue_declare -> connect
        $basicPublishSpan = $sendTraces[1];

        $receiveTraces = $receiveTraces[3]; // There isn't a root span
        // Spans: connect -> queue_declare -> basic_consume & basic_consume_ok -> basic_deliver
        $basicDeliverSpan = $receiveTraces[0];

        $this->assertNotSame($basicPublishSpan['trace_id'], $basicDeliverSpan['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $basicDeliverSpan);
    }

    public function testBatchedPublishing()
    {
        $traces = $this->isolateTracer(function () {
            $producerConnection = $this->connectionToServer();
            $producerChannel = $producerConnection->channel();
            $producerChannel->queue_declare('hello-1', false, false, false, false);
            $producerChannel->queue_declare('hello-2', false, false, false, false);
            $producerChannel->batch_basic_publish(new AMQPMessage('hello there'), '', 'hello-1');
            $producerChannel->batch_basic_publish(new AMQPMessage('world'), '', 'hello-2');
            $producerChannel->publish_batch();

            // Wait for the server to ack the messages, but no more than 3 seconds
            // Note: Blocking call
            $producerChannel->wait_for_pending_acks(3);

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
                '_dd.svc_src' => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare hello-1'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                '_dd.svc_src' => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello-1',
            ]),
            SpanAssertion::build(
                'amqp.queue.declare',
                'amqp',
                'queue',
                'queue.declare hello-2'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'client',
                Tag::COMPONENT                  => 'amqp',
                '_dd.svc_src' => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_DESTINATION             => 'hello-2',
            ]),
            SpanAssertion::build(
                'amqp.batch_basic.add',
                'amqp',
                'queue',
                'batch_basic.add <default> -> hello-1'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'producer',
                Tag::COMPONENT                  => 'amqp',
                '_dd.svc_src' => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::RABBITMQ_ROUTING_KEY       => 'hello-1',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 11,
                Tag::RABBITMQ_EXCHANGE          => '<default>',
            ]),
            SpanAssertion::build(
                'amqp.batch_basic.add',
                'amqp',
                'queue',
                'batch_basic.add <default> -> hello-2'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'producer',
                Tag::COMPONENT                  => 'amqp',
                '_dd.svc_src' => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::RABBITMQ_ROUTING_KEY       => 'hello-2',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_MESSAGE_PAYLOAD_SIZE    => 5,
                Tag::RABBITMQ_EXCHANGE          => '<default>',
            ]),
            SpanAssertion::build(
                'amqp.publish_batch',
                'amqp',
                'queue',
                'publish_batch'
            )->withExactTags([
                Tag::SPAN_KIND                  => 'producer',
                Tag::COMPONENT                  => 'amqp',
                '_dd.svc_src' => 'amqp',
                Tag::MQ_SYSTEM                  => 'rabbitmq',
                Tag::MQ_DESTINATION_KIND        => 'queue',
                Tag::MQ_PROTOCOL                => 'AMQP',
                Tag::MQ_PROTOCOL_VERSION        => AMQPChannel::getProtocolVersion(),
                Tag::MQ_OPERATION               => 'send',
            ])
        ]);
    }

    protected function ddTearDown()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED', 'DD_DISTRIBUTED_TRACING']);
        parent::ddTearDown();
    }

    private static function dsmContext(AMQPMessage $message)
    {
        if (!$message->has('application_headers')) {
            return null;
        }
        return $message->get('application_headers')->getNativeData()['dd-pathway-ctx-base64'] ?? null;
    }

    private static function dsmHash($context)
    {
        $bytes = base64_decode($context, true);
        return sprintf('%u', unpack('P', substr($bytes, 0, 8))[1]);
    }

    /**
     * The hash the last of $checkpoints ([edge tags, context the message arrived with]) gets on
     * a fresh pathway. The hashes themselves are checked against system-tests' in
     * components-rs/data_streams.rs; these tests check the tags, the contexts and the parents.
     */
    private static function dsmHashOnFreshPathway(array $checkpoints)
    {
        \DDTrace\Internal\dsm_push_pathway();
        try {
            foreach ($checkpoints as list($edgeTags, $context)) {
                $last = \DDTrace\Internal\dsm_set_checkpoint($edgeTags, $context);
            }
        } finally {
            \DDTrace\Internal\dsm_pop_pathway();
        }
        return self::dsmHash($last);
    }

    /**
     * A message published inside a basic_consume callback links to the message being consumed,
     * and one published after the callback does not.
     */
    public function testDataStreamsConsumeCallbackLinksProducesToTheMessage()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true']);
        $contexts = [];
        $this->isolateTracer(function () use (&$contexts) {
            // Start from a fresh pathway, whatever the tests before left on the process's.
            \DDTrace\Internal\dsm_push_pathway();
            try {
                $connection = $this->connectionToServer();
                $channel = $connection->channel();
                $channel->queue_declare('dsm_orders', false, false, true, false);
                $channel->queue_declare('dsm_billing', false, false, true, false);

                $order = new AMQPMessage('order');
                $channel->basic_publish($order, '', 'dsm_orders');
                $contexts['sent'] = self::dsmContext($order);

                $callback = function (AMQPMessage $message) use ($channel, &$contexts) {
                    $contexts['received'] = self::dsmContext($message);
                    $invoice = new AMQPMessage('invoice');
                    $channel->basic_publish($invoice, '', 'dsm_billing');
                    $contexts['in callback'] = self::dsmContext($invoice);
                };
                $channel->basic_consume('dsm_orders', '', false, true, false, false, $callback);
                $channel->wait(null, false, 5);

                $report = new AMQPMessage('report');
                $channel->basic_publish($report, '', 'dsm_billing');
                $contexts['after callback'] = self::dsmContext($report);

                $channel->close();
                $connection->close();
            } finally {
                \DDTrace\Internal\dsm_pop_pathway();
            }
        });

        $publishOrders = [['direction:out', 'topic:dsm_orders', 'type:rabbitmq'], null];
        $publishBilling = [['direction:out', 'topic:dsm_billing', 'type:rabbitmq'], null];
        $consumeOrders = [['direction:in', 'topic:dsm_orders', 'type:rabbitmq'], $contexts['sent']];

        $this->assertSame(self::dsmHashOnFreshPathway([$publishOrders]), self::dsmHash($contexts['sent']));
        $this->assertSame($contexts['sent'], $contexts['received']);
        $this->assertSame(
            self::dsmHashOnFreshPathway([$consumeOrders, $publishBilling]),
            self::dsmHash($contexts['in callback'])
        );
        // After the callback, the publish continues the pathway from before it: the run of
        // publishes that the first one started.
        $this->assertSame(
            self::dsmHashOnFreshPathway([$publishBilling]),
            self::dsmHash($contexts['after callback'])
        );
    }

    /**
     * A message got with basic_get becomes the request's pathway, so what is published after it
     * links to it. A publish to a named exchange is on the edge of the exchange.
     */
    public function testDataStreamsBasicGet()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true']);
        $contexts = [];
        $this->isolateTracer(function () use (&$contexts) {
            \DDTrace\Internal\dsm_push_pathway();
            try {
                $connection = $this->connectionToServer();
                $channel = $connection->channel();
                $channel->exchange_declare('dsm_get_exchange', 'direct', false, false, true);
                $channel->queue_declare('dsm_get_queue', false, false, true, false);
                $channel->queue_bind('dsm_get_queue', 'dsm_get_exchange', 'dsm_key');
                $channel->queue_declare('dsm_get_billing', false, false, true, false);

                $order = new AMQPMessage('order');
                $channel->basic_publish($order, 'dsm_get_exchange', 'dsm_key');
                $contexts['sent'] = self::dsmContext($order);

                $message = $channel->basic_get('dsm_get_queue', true);
                $contexts['received'] = self::dsmContext($message);

                $invoice = new AMQPMessage('invoice');
                $channel->basic_publish($invoice, '', 'dsm_get_billing');
                $contexts['after get'] = self::dsmContext($invoice);

                $channel->close();
                $connection->close();
            } finally {
                \DDTrace\Internal\dsm_pop_pathway();
            }
        });

        $this->assertSame(
            self::dsmHashOnFreshPathway([[
                ['direction:out', 'exchange:dsm_get_exchange', 'has_routing_key:true', 'type:rabbitmq'],
                null,
            ]]),
            self::dsmHash($contexts['sent'])
        );
        $this->assertSame($contexts['sent'], $contexts['received']);
        $this->assertSame(
            self::dsmHashOnFreshPathway([
                [['direction:in', 'topic:dsm_get_queue', 'type:rabbitmq'], $contexts['sent']],
                [['direction:out', 'topic:dsm_get_billing', 'type:rabbitmq'], null],
            ]),
            self::dsmHash($contexts['after get'])
        );
    }

    /**
     * The pathway context is sent even with distributed tracing off, and adding it neither
     * changes the AMQP types of the application's headers nor the table the application passed.
     */
    public function testDataStreamsContextKeepsApplicationHeaders()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true', 'DD_DISTRIBUTED_TRACING=false']);
        $userHeaders = new AMQPTable();
        $userHeaders->set('x-short', 3, AMQPTable::T_INT_SHORT);
        $userHeaders->set('x-longlong', 5, AMQPTable::T_INT_LONGLONG);
        $receivedTypes = [];
        $this->isolateTracer(function () use ($userHeaders, &$receivedTypes) {
            $connection = $this->connectionToServer();
            $channel = $connection->channel();
            $channel->queue_declare('dsm_headers', false, false, true, false);

            $message = new AMQPMessage('order', ['application_headers' => $userHeaders]);
            $channel->basic_publish($message, '', 'dsm_headers');

            $received = $channel->basic_get('dsm_headers', true);
            foreach ($received->get('application_headers') as $key => list($type, $value)) {
                $receivedTypes[$key] = $type;
            }

            $channel->close();
            $connection->close();
        });

        $this->assertSame(AMQPTable::T_INT_SHORT, $receivedTypes['x-short']);
        $this->assertSame(AMQPTable::T_INT_LONGLONG, $receivedTypes['x-longlong']);
        $this->assertSame(AMQPTable::T_STRING_LONG, $receivedTypes['dd-pathway-ctx-base64']);
        $this->assertArrayNotHasKey('x-datadog-trace-id', $receivedTypes);
        $this->assertSame(['x-short' => 3, 'x-longlong' => 5], $userHeaders->getNativeData());
    }

    /**
     * With Data Streams Monitoring off, no context is sent and callbacks are not wrapped.
     */
    public function testDataStreamsDisabledByDefault()
    {
        $result = [];
        $this->isolateTracer(function () use (&$result) {
            $connection = $this->connectionToServer();
            $channel = $connection->channel();
            $channel->queue_declare('dsm_disabled', false, false, true, false);

            $message = new AMQPMessage('order');
            $channel->basic_publish($message, '', 'dsm_disabled');
            $result['sent'] = self::dsmContext($message);

            $callback = function (AMQPMessage $message) {
            };
            $consumerTag = $channel->basic_consume('dsm_disabled', '', false, true, false, false, $callback);
            $result['callback kept'] = $channel->callbacks[$consumerTag] === $callback;

            $channel->close();
            $connection->close();
        });

        $this->assertNull($result['sent']);
        $this->assertTrue($result['callback kept']);
    }
}
