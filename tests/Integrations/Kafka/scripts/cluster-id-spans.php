<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;

$topicName = $argv[1];
$brokers = $argv[2] ?? 'kafka-integration:9092';

// A Producer given its brokers with addBrokers() rather than in its Conf.
$producer = new Producer(new Conf());
$producer->addBrokers($brokers);
$topic = $producer->newTopic($topicName);
// So that the producer has the cluster id from its brokers' answer before it produces.
$producer->getMetadata(false, $topic, 10000);
$topic->producev(RD_KAFKA_PARTITION_UA, 0, 'cluster-id-spans');
$producer->flush(10000);

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$conf->set('group.id', 'cluster-id-spans');
$conf->set('auto.offset.reset', 'earliest');
$consumer = new KafkaConsumer($conf);
$consumer->subscribe([$topicName]);
$deadline = microtime(true) + 20;
while (microtime(true) < $deadline) {
    if ($consumer->consume(1000)->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
        echo "consumed\n";
        break;
    }
}
$consumer->close();

// A low-level Consumer given its brokers with addBrokers(), consuming through a Queue.
$conf = new Conf();
$conf->set('group.id', 'cluster-id-spans-lowlevel');
$lowLevel = new Consumer($conf);
$lowLevel->addBrokers($brokers);
// librdkafka counts a broker it already knows as added again
$lowLevel->addBrokers($brokers);
$lowLevelTopic = $lowLevel->newTopic($topicName);
$queue = $lowLevel->newQueue();
$lowLevelTopic->consumeQueueStart(0, RD_KAFKA_OFFSET_BEGINNING, $queue);
$deadline = microtime(true) + 20;
while (microtime(true) < $deadline) {
    $message = $queue->consume(1000);
    if ($message && $message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
        echo "consumed from queue\n";
        break;
    }
}
$lowLevelTopic->consumeStop(0);
