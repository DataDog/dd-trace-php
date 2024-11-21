<?php

namespace DDTrace\Tests\Integrations\PeclAMQP;

use DDTrace\Tests\Common\IntegrationTestCase;

class PeclAMQPTest extends IntegrationTestCase
{
    const FIELDS_TO_IGNORE = [
        'metrics.php.compilation.total_time_ms',
        'metrics.php.memory.peak_usage_bytes',
        'metrics.php.memory.peak_real_usage_bytes',
        'meta.error.stack',
    ];

    protected function connectionToServer()
    {
        return new \AMQPConnection([
            'host' => 'rabbitmq_integration',
            'port' => 5672,
            //'vhost' => '/',
            'login' => 'guest',
            'password' => 'guest',
        ]);
    }

    /*
    public function testHelloWorld()
    {
        self::putEnv('DD_INSTRUMENTATION_TELEMETRY_ENABLED=false');
        self::putEnv('DD_TRACE_DEBUG=true');

        $this->isolateTracerSnapshot(function () {
            $consumerConnection = $this->connectionToServer();
            $consumerConnection->connect();
            $consumerChannel = new \AMQPChannel($consumerConnection);
            $consumerExchange = new \AMQPExchange($consumerChannel);
            $routingKey = 'hello';
            $consumerQueue = new \AMQPQueue($consumerChannel);
            $consumerQueue->setName($routingKey);
            $consumerQueue->setFlags(AMQP_NOPARAM);
            $consumerQueue->declareQueue();

            $producerConnection = $this->connectionToServer();
            $producerConnection->connect();
            $producerChannel = new \AMQPChannel($producerConnection);
            $producerExchange = new \AMQPExchange($producerChannel);
            $producerQueue = new \AMQPQueue($producerChannel);
            $producerQueue->setName($routingKey);
            $producerQueue->setFlags(AMQP_NOPARAM);
            $producerQueue->declareQueue();
            $message = 'howdy-do';
            $producerExchange->publish($message, $routingKey);

            $callback_func = function(\AMQPEnvelope $message, \AMQPQueue $q) use (&$max_consume) {
                echo PHP_EOL, "------------", PHP_EOL;
                echo " [x] Received ", $message->getBody(), PHP_EOL;
                echo PHP_EOL, "------------", PHP_EOL;

                $q->nack($message->getDeliveryTag());
                sleep(1);
            };
            $consumerQueue->consume($callback_func);
            $consumerConnection->disconnect();
            $producerConnection->disconnect();
        });
    }
    */

    public function testDistributedTracing()
    {
        self::putEnv('DD_TRACE_DEBUG_PRNG_SEED=42'); // Not necessary, but makes it easier to debug locally

        list($producerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/send.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
            ],
            [],
            null,
            true
        );

        //echo json_encode($producerTraces, JSON_PRETTY_PRINT) . PHP_EOL;
        $this->snapshotFromTraces(
            $producerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.pecl_amqp.pecl_amqp_test.test_distributed_tracing_producer'
        );
        //echo $output;

        list($consumerTraces, $output) = $this->inCli(
            __DIR__ . '/scripts/receive.php',
            [
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => 'false',
            ],
            [],
            null,
            true
        );

        //echo json_encode($consumerTraces, JSON_PRETTY_PRINT) . PHP_EOL;
        $this->snapshotFromTraces(
            $consumerTraces,
            self::FIELDS_TO_IGNORE,
            'tests.integrations.pecl_amqp.pecl_amqp_test.test_distributed_tracing_consumer'
        );
        //echo $output;
    }
}
