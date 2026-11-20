<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;

require __DIR__ . '/print-dsm-checkpoints.php';

// "produce" alternates between two new topics of one producer, "consume" reads both with a
// consumer subscribed to them, and "queue" with a low-level consumer's queue. Each prints its
// checkpoints. The topics and the group are named after the run.
$run = $argv[2];
$topics = ["test-dsm-a-$run", "test-dsm-b-$run"];
$brokers = 'kafka-integration:9092';
$group = "dsm-topics-$run";

// Consumes until it has had a message of every topic.
function consumeAll(callable $consume, array $topics)
{
    $pending = array_flip($topics);
    $deadline = microtime(true) + 20;
    while ($pending && microtime(true) < $deadline) {
        $message = $consume();
        if ($message && $message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            unset($pending[$message->topic_name]);
        }
    }
    echo 'missing: ', implode(',', array_keys($pending)) ?: 'none', PHP_EOL;
}

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
switch ($argv[1]) {
    case 'produce':
        $producer = new Producer($conf);
        $producerTopics = [];
        foreach ($topics as $topic) {
            $producerTopics[] = $producer->newTopic($topic);
        }
        foreach ($producerTopics as $producerTopic) {
            // Creates the topic.
            $producer->getMetadata(false, $producerTopic, 10000);
        }
        for ($i = 0; $i < 4; $i++) {
            $producerTopics[$i % 2]->producev(RD_KAFKA_PARTITION_UA, 0, "message $i");
        }
        $producer->flush(10000);
        break;
    case 'consume':
        $conf->set('group.id', $group);
        $conf->set('auto.offset.reset', 'earliest');
        $consumer = new KafkaConsumer($conf);
        $consumer->subscribe($topics);
        consumeAll(function () use ($consumer) {
            return $consumer->consume(1000);
        }, $topics);
        $consumer->close();
        break;
    case 'queue':
        $conf->set('group.id', $group);
        $consumer = new Consumer($conf);
        $queue = $consumer->newQueue();
        $consumerTopics = [];
        foreach ($topics as $topic) {
            $consumerTopics[] = $consumerTopic = $consumer->newTopic($topic);
            $consumerTopic->consumeQueueStart(0, RD_KAFKA_OFFSET_BEGINNING, $queue);
        }
        consumeAll(function () use ($queue) {
            return $queue->consume(1000);
        }, $topics);
        foreach ($consumerTopics as $consumerTopic) {
            $consumerTopic->consumeStop(0);
        }
        break;
}
