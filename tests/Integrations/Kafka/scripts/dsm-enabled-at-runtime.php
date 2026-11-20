<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;

require __DIR__ . '/print-dsm-checkpoints.php';

// Produces and consumes a message with DSM off, then turns DSM on, as Remote Config would, and
// does the same with the same producer, topic, KafkaConsumer and low-level consumer's queue.
// Prints the checkpoints, and each consumed message's pathway context header.
$run = $argv[1];
$topicName = "test-dsm-runtime-$run";
$brokers = 'kafka-integration:9092';

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$producer = new Producer($conf);
$topic = $producer->newTopic($topicName);
// Creates the topic.
$producer->getMetadata(false, $topic, 10000);

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$conf->set('group.id', "dsm-runtime-$run");
$conf->set('auto.offset.reset', 'earliest');
$consumer = new KafkaConsumer($conf);
$consumer->subscribe([$topicName]);

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$conf->set('group.id', "dsm-runtime-queue-$run");
$lowLevel = new Consumer($conf);
$queue = $lowLevel->newQueue();
$lowLevelTopic = $lowLevel->newTopic($topicName);
$lowLevelTopic->consumeQueueStart(0, RD_KAFKA_OFFSET_BEGINNING, $queue);

function consumeOne(callable $consume, string $name)
{
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        $message = $consume();
        if ($message && $message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            echo sprintf(
                "%s consumed %s: %s\n",
                $name,
                $message->payload,
                isset($message->headers['dd-pathway-ctx-base64']) ? 'context' : 'no context'
            );
            return;
        }
    }
    echo "$name consumed nothing\n";
}

foreach (['off', 'on'] as $dsm) {
    if ($dsm === 'on') {
        ini_set('datadog.data_streams_enabled', '1');
    }
    echo "dsm $dsm\n";
    $topic->producev(RD_KAFKA_PARTITION_UA, 0, "dsm $dsm");
    $producer->flush(10000);
    consumeOne(function () use ($consumer) {
        return $consumer->consume(1000);
    }, 'consumer');
    consumeOne(function () use ($queue) {
        return $queue->consume(1000);
    }, 'queue');
}

$lowLevelTopic->consumeStop(0);
$consumer->close();
