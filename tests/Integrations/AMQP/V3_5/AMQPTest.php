<?php

namespace DDTrace\Tests\Integrations\AMQP\V3_5;

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

    function testHelloWorld()
    {
        // The simplest thing that does something
        $traces = $this->isolateTracer(function () {
            $consumerConnection = $this->connectionToServer();
            $consumerChannel = $consumerConnection->channel();
            $consumerChannel->queue_declare('hello', false, false, false, false);
            $callback = function ($msg) {
                echo " [x] Received ", $msg->body, "\n";
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
                'amqp.basic.deliver',
                'amqp',
                'amqp',
                'amqp.basic.deliver')->withExactTags([
                    'messaging.system' => 'rabbitmq',
                    Tag::RABBITMQ_ROUTING_KEY => 'hello',
                    'messaging.destination_kind' => 'queue',
                    'messaging.protocol' => 'amqp',
                    'messaging.protocol_version' => AMQPChannel::getProtocolVersion(),
                    'messaging.message_payload_size' => 12,
                    'messaging.operation' => 'receive',
            ])->withExistingTagsNames(['messaging.consumer_id']),
            SpanAssertion::build(
                'amqp.basic.publish',
                'amqp',
                'amqp',
                'amqp.basic.publish')->withExactTags([
                    'messaging.operation' => 'send',
                    'messaging.system' => 'rabbitmq',
                    'messaging.destination' => 'hello',
                    'messaging.destination_kind' => 'queue',
                    'messaging.protocol' => 'amqp',
                    'messaging.protocol_version' => AMQPChannel::getProtocolVersion(),
                    'messaging.message_payload_size' => 12,
                ])->withExistingTagsNames(['messaging.consumer_id']),
            ]);
    }

    function testRouting()
    {
        // Receiving messages selectively
        // @see https://www.rabbitmq.com/tutorials/tutorial-four-php.html
        $traces = $this->isolateTracer(function () {
            $receiveLogsConnection = $this->connectionToServer();
            $receiveLogsChannel = $receiveLogsConnection->channel();
            $receiveLogsChannel->exchange_declare('direct_logs', 'direct', false, false, false);
            list($receiveLogsQueueName, ,) = $receiveLogsChannel->queue_declare("", false, false, true, false);

            $severities = ['info', 'warning', 'error'];

            foreach ($severities as $severity) {
                $receiveLogsChannel->queue_bind($receiveLogsQueueName, 'direct_logs', $severity);
            }

            $callback = function ($msg) {
                echo ' [x] ', $msg->delivery_info['routing_key'], ':', $msg->body, "\n";
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

        $this->assertSpans($traces, [
            SpanAssertion::exists('ThisSpanDoesntExist')
        ]);
    }
}
