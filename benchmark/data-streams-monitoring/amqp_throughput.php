<?php

// RabbitMQ publish/consume throughput workload for DSM overhead benchmarking.
//
// The AMQP counterpart of kafka_throughput.php, with the same message-level
// work (MESSAGE_COUNT PAYLOAD_SIZE-byte messages carrying NUM_HEADERS headers,
// a commit -- here an ack -- every COMMIT_EVERY messages and after the last
// one) so the two brokers' numbers can be read side by side. None of the other
// languages' data-streams-monitoring branches has a RabbitMQ workload.
//
// The client is php-amqplib, which dd-trace-php's AMQP integration
// instruments (src/DDTrace/Integrations/AMQP): it sets the publish checkpoint
// in basic_publish, and the consume checkpoint in a wrapper around the
// basic_consume callback, before the callback runs. This uses basic_consume
// and wait() rather than basic_get, the pattern long-running consumers use.
//
// Tracing/DSM are controlled purely by environment, as for the Kafka
// workload; this file contains no tracer-specific code.

require_once getenv('AMQP_AUTOLOAD') ?: '/opt/php-amqplib/vendor/autoload.php';
require_once __DIR__ . '/common.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

const AMQP_CONSUMER_TAG = 'dsm-benchmark';

// Connects to the broker, retrying until it's up: steps/start-rabbitmq.sh
// returns before RabbitMQ accepts connections.
function amqp_connect(): AMQPStreamConnection
{
    $deadline = now_ms() + TIMEOUT_MS;
    while (true) {
        try {
            return new AMQPStreamConnection(
                getenv('AMQP_HOST') ?: 'localhost',
                (int) (getenv('AMQP_PORT') ?: 5672),
                'guest',
                'guest'
            );
        } catch (Throwable $e) {
            if (now_ms() > $deadline) {
                throw new RuntimeException('Could not connect to RabbitMQ within ' . TIMEOUT_MS . ' ms', 0, $e);
            }
            usleep(200000);
        }
    }
}

function run_amqp_worker(string $queue, int $commitEvery): array
{
    $payload = str_repeat('x', PAYLOAD_SIZE);
    $headers = [];
    for ($j = 0; $j < NUM_HEADERS; $j++) {
        $headers["key$j"] = "value$j";
    }

    // Setup: not timed. Connections, channels, the queue and the consumer
    // all exist before publish_start. The publisher and consumer have their
    // own connections, as the separate applications they stand for would.
    $publisherConnection = amqp_connect();
    $consumerConnection = amqp_connect();
    try {
        $publisher = $publisherConnection->channel();
        $consumer = $consumerConnection->channel();
        $consumer->queue_declare($queue, false, false, false, false);
        // Publisher confirms, so the publish phase ends when the broker has
        // every message -- what the Kafka workload's flush waits for.
        $publisher->confirm_select();

        $consumed = 0;
        $consumer->basic_consume(
            $queue,
            AMQP_CONSUMER_TAG,
            false,
            false,
            false,
            false,
            static function (AMQPMessage $message) use (&$consumed, $commitEvery) {
                $consumed++;
                // Acks every message up to this one, like a Kafka offset
                // commit.
                if ($consumed >= MESSAGE_COUNT || $consumed % $commitEvery === 0) {
                    $message->ack(true);
                }
            }
        );

        // Phase 1: publish all messages to the default exchange, routed to
        // the queue by name.
        $produceStart = now_ms();
        for ($i = 0; $i < MESSAGE_COUNT; $i++) {
            $message = new AMQPMessage($payload, [
                'message_id' => (string) microtime(true),
                'application_headers' => new AMQPTable($headers),
            ]);
            $publisher->basic_publish($message, '', $queue);
        }
        $publisher->wait_for_pending_acks_returns(TIMEOUT_MS / 1000);
        $produceMs = now_ms() - $produceStart;

        // Phase 2: consume all messages. The broker has been pushing them to
        // the consumer since they were published; wait() reads them and runs
        // the callback, one message at a time.
        $consumeStart = now_ms();
        $deadline = $consumeStart + TIMEOUT_MS;
        while ($consumed < MESSAGE_COUNT && now_ms() < $deadline) {
            try {
                $consumer->wait(null, false, 1.0);
            } catch (AMQPTimeoutException $e) {
                // Retried until the deadline.
            }
        }
        $consumeMs = now_ms() - $consumeStart;

        if ($consumed !== MESSAGE_COUNT) {
            throw new RuntimeException("Consumed $consumed/" . MESSAGE_COUNT . " messages from queue $queue");
        }

        // Teardown: not timed.
        $consumer->basic_cancel(AMQP_CONSUMER_TAG);
        $consumer->queue_delete($queue);
    } finally {
        $publisherConnection->close();
        $consumerConnection->close();
    }

    return ['produce_ms' => $produceMs, 'consume_ms' => $consumeMs];
}

// Runs one full publish/consume pass on a queue named after $runId, so
// repeated in-process iterations stay isolated.
function run_benchmark(int $runId, int $commitEvery): array
{
    check_num_threads();
    $baseQueue = getenv('AMQP_QUEUE') ?: 'benchmark-queue';

    return run_amqp_worker("$baseQueue-$runId", $commitEvery);
}
