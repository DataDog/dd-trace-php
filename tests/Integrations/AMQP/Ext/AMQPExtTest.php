<?php

namespace DDTrace\Tests\Integrations\AMQP\Ext;

use DDTrace\Integrations\AMQP\AMQPIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Integrations\AMQP\DataStreamsHelpers;

/**
 * ext-amqp, the PECL extension, gets Data Streams Monitoring checkpoints but no spans.
 */
class AMQPExtTest extends IntegrationTestCase
{
    use DataStreamsHelpers;

    protected static $lockedResource = "amqp";

    protected function ddSetUp()
    {
        if (!extension_loaded('amqp')) {
            $this->markTestSkipped('ext-amqp is not loaded');
        }
        parent::ddSetUp();
    }

    protected function ddTearDown()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED']);
        parent::ddTearDown();
    }

    private function channel()
    {
        $connection = new \AMQPConnection([
            'host' => 'rabbitmq-integration',
            'port' => 5672,
            'login' => 'guest',
            'password' => 'guest',
            'read_timeout' => 5,
        ]);
        $connection->connect();
        return new \AMQPChannel($connection);
    }

    private static function queue(\AMQPChannel $channel, $name)
    {
        $queue = new \AMQPQueue($channel);
        $queue->setName($name);
        $queue->setFlags(AMQP_EXCLUSIVE);
        $queue->declareQueue();
        return $queue;
    }

    private static function dsmContext($envelope)
    {
        return $envelope instanceof \AMQPEnvelope
            ? ($envelope->getHeaders()['dd-pathway-ctx-base64'] ?? null)
            : null;
    }

    // Whether the integration's wrapper is on the stack, i.e. the running callback was wrapped.
    private static function calledThroughIntegration()
    {
        foreach (debug_backtrace() as $frame) {
            if (($frame['class'] ?? '') === AMQPIntegration::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * A message published inside a consume() callback links to the message being consumed, and
     * one published after the callback does not.
     */
    public function testDataStreamsConsumeCallbackLinksPublishesToTheMessage()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true']);
        $contexts = [];
        $this->isolateTracer(function () use (&$contexts) {
            // Start from a fresh pathway, whatever the tests before left on the process's.
            \DDTrace\Internal\dsm_push_pathway();
            try {
                $channel = $this->channel();
                $orders = self::queue($channel, 'dsm_ext_orders');
                $billing = self::queue($channel, 'dsm_ext_billing');
                $exchange = new \AMQPExchange($channel); // the default exchange

                $exchange->publish('order', 'dsm_ext_orders');
                $orders->consume(function (\AMQPEnvelope $envelope) use ($exchange, &$contexts) {
                    $contexts['wrapped'] = self::calledThroughIntegration();
                    $contexts['received'] = self::dsmContext($envelope);
                    $exchange->publish('invoice', 'dsm_ext_billing');
                    return false;
                }, AMQP_AUTOACK);
                $exchange->publish('report', 'dsm_ext_billing');

                $contexts['in callback'] = self::dsmContext($billing->get(AMQP_AUTOACK));
                $contexts['after callback'] = self::dsmContext($billing->get(AMQP_AUTOACK));
                $channel->getConnection()->disconnect();
            } finally {
                \DDTrace\Internal\dsm_pop_pathway();
            }
        });

        $publishBilling = [['direction:out', 'topic:dsm_ext_billing', 'type:rabbitmq'], null];
        $this->assertTrue($contexts['wrapped']);
        $this->assertSame(
            self::dsmHashOnFreshPathway([[['direction:out', 'topic:dsm_ext_orders', 'type:rabbitmq'], null]]),
            self::dsmHash($contexts['received'])
        );
        $this->assertSame(
            self::dsmHashOnFreshPathway([
                [['direction:in', 'topic:dsm_ext_orders', 'type:rabbitmq'], $contexts['received']],
                $publishBilling,
            ]),
            self::dsmHash($contexts['in callback'])
        );
        // After the callback, the publish continues the pathway from before it: the run of
        // publishes that the first one started.
        $this->assertSame(self::dsmHashOnFreshPathway([$publishBilling]), self::dsmHash($contexts['after callback']));
    }

    /**
     * A callback registered on one queue also gets the messages of the queues consumed before
     * it on the channel; the checkpoint is on the edge of the queue each message came from.
     */
    public function testDataStreamsOneCallbackForSeveralQueues()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true']);
        $contexts = [];
        $this->isolateTracer(function () use (&$contexts) {
            \DDTrace\Internal\dsm_push_pathway();
            try {
                $channel = $this->channel();
                $first = self::queue($channel, 'dsm_ext_first');
                $second = self::queue($channel, 'dsm_ext_second');
                $billing = self::queue($channel, 'dsm_ext_multi_billing');
                $exchange = new \AMQPExchange($channel);

                $exchange->publish('order', 'dsm_ext_first');
                // Without a callback, consume() only subscribes; the next callback gets its messages.
                $first->consume(null, AMQP_AUTOACK);
                $second->consume(function (\AMQPEnvelope $envelope, \AMQPQueue $queue) use ($exchange, &$contexts) {
                    $contexts['queue'] = $queue->getName();
                    $contexts['received'] = self::dsmContext($envelope);
                    $exchange->publish('invoice', 'dsm_ext_multi_billing');
                    return false;
                }, AMQP_AUTOACK);

                $contexts['in callback'] = self::dsmContext($billing->get(AMQP_AUTOACK));
                $channel->getConnection()->disconnect();
            } finally {
                \DDTrace\Internal\dsm_pop_pathway();
            }
        });

        $this->assertSame('dsm_ext_first', $contexts['queue']);
        $this->assertSame(
            self::dsmHashOnFreshPathway([
                [['direction:in', 'topic:dsm_ext_first', 'type:rabbitmq'], $contexts['received']],
                [['direction:out', 'topic:dsm_ext_multi_billing', 'type:rabbitmq'], null],
            ]),
            self::dsmHash($contexts['in callback'])
        );
    }

    /**
     * A message got with get() becomes the request's pathway, so what is published after it
     * links to it. A publish to a named exchange is on the edge of the exchange.
     */
    public function testDataStreamsGet()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true']);
        $contexts = [];
        $this->isolateTracer(function () use (&$contexts) {
            \DDTrace\Internal\dsm_push_pathway();
            try {
                $channel = $this->channel();
                $exchange = new \AMQPExchange($channel);
                $exchange->setName('dsm_ext_exchange');
                $exchange->setType(AMQP_EX_TYPE_DIRECT);
                $exchange->setFlags(AMQP_AUTODELETE);
                $exchange->declareExchange();
                $queue = self::queue($channel, 'dsm_ext_get');
                $queue->bind('dsm_ext_exchange', 'dsm_key');
                $billing = self::queue($channel, 'dsm_ext_get_billing');

                $exchange->publish('order', 'dsm_key');
                $contexts['received'] = self::dsmContext($queue->get(AMQP_AUTOACK));
                (new \AMQPExchange($channel))->publish('invoice', 'dsm_ext_get_billing');
                $contexts['after get'] = self::dsmContext($billing->get(AMQP_AUTOACK));
                $channel->getConnection()->disconnect();
            } finally {
                \DDTrace\Internal\dsm_pop_pathway();
            }
        });

        $this->assertSame(
            self::dsmHashOnFreshPathway([[
                ['direction:out', 'exchange:dsm_ext_exchange', 'has_routing_key:true', 'type:rabbitmq'],
                null,
            ]]),
            self::dsmHash($contexts['received'])
        );
        $this->assertSame(
            self::dsmHashOnFreshPathway([
                [['direction:in', 'topic:dsm_ext_get', 'type:rabbitmq'], $contexts['received']],
                [['direction:out', 'topic:dsm_ext_get_billing', 'type:rabbitmq'], null],
            ]),
            self::dsmHash($contexts['after get'])
        );
    }

    /**
     * The context is added next to the application's headers and attributes, and also to a
     * publish made without any.
     */
    public function testDataStreamsContextKeepsApplicationAttributes()
    {
        self::putEnvAndReloadConfig(['DD_DATA_STREAMS_ENABLED=true']);
        $received = [];
        $this->isolateTracer(function () use (&$received) {
            $channel = $this->channel();
            $queue = self::queue($channel, 'dsm_ext_headers');
            $exchange = new \AMQPExchange($channel);

            $exchange->publish('one', 'dsm_ext_headers', AMQP_NOPARAM, [
                'content_type' => 'text/plain',
                'headers' => ['x-a' => 'abcd', 'x-n' => 5],
            ]);
            $exchange->publish('two', 'dsm_ext_headers');

            $received['one'] = $queue->get(AMQP_AUTOACK);
            $received['two'] = $queue->get(AMQP_AUTOACK);
            $channel->getConnection()->disconnect();
        });

        $headers = $received['one']->getHeaders();
        $this->assertSame('abcd', $headers['x-a']);
        $this->assertSame(5, $headers['x-n']);
        $this->assertTrue(is_string($headers['dd-pathway-ctx-base64']));
        $this->assertSame('text/plain', $received['one']->getContentType());
        $this->assertSame('two', $received['two']->getBody());
        $this->assertTrue(is_string(self::dsmContext($received['two'])));
    }

    /**
     * With Data Streams Monitoring off, no context is sent and callbacks are not wrapped.
     */
    public function testDataStreamsDisabledByDefault()
    {
        $result = [];
        $this->isolateTracer(function () use (&$result) {
            $channel = $this->channel();
            $queue = self::queue($channel, 'dsm_ext_disabled');
            (new \AMQPExchange($channel))->publish('order', 'dsm_ext_disabled');

            $queue->consume(function (\AMQPEnvelope $envelope) use (&$result) {
                $result['headers'] = $envelope->getHeaders();
                $result['wrapped'] = self::calledThroughIntegration();
                return false;
            }, AMQP_AUTOACK);
            $channel->getConnection()->disconnect();
        });

        $this->assertArrayNotHasKey('dd-pathway-ctx-base64', $result['headers']);
        $this->assertFalse($result['wrapped']);
    }
}
