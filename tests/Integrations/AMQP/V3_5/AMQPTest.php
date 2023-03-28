<?php

namespace DDTrace\Tests\Integrations\AMQP\V3_5;

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
        return new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
    }

    /**
     * @param AMQPChannel $channel
     * @param string $queueName
     */
    protected function declareQueue($channel, $queueName)
    {
        $channel->queue_declare($queueName, false, false, false, false);
    }

    function testHelloWorld()
    {
        $traces = $this->isolateTracer(function () {
            $connection = $this->connectionToServer();
            $channel = $connection->channel();
            $queueName = 'hello';
            $this->declareQueue($channel, $queueName);
            $message = 'Hello World!';
            $channel->basic_publish(new AMQPMessage($message), '', $queueName);
            $channel->basic_consume($queueName, '', false, true, false, false, function ($msg) {
                echo " [x] Received ", $msg->body, "\n";
            });
            while (count($channel->callbacks)) {
                $channel->wait();
            }
            $channel->close();
            $connection->close();
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('ThisSpanDoesntExist')
        ]);
    }
}
