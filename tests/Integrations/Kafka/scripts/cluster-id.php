<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;

$topicName = $argv[1];
$brokers = $argv[2] ?? 'kafka-integration:9092';

function report(string $what, $client)
{
    echo sprintf("%s: %s\n", $what, var_export(\DDTrace\Internal\kafka_cluster_id($client), true));
}

// No broker answers, so librdkafka never has metadata.
$conf = new Conf();
$conf->set('bootstrap.servers', '127.0.0.1:1');
report('unreachable producer', new Producer($conf));

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$conf->setDrMsgCb(function (Producer $kafka, Message $message) {
    report('delivery report', $kafka);
});
$producer = new Producer($conf);
$producer->newTopic($topicName)->producev(RD_KAFKA_PARTITION_UA, 0, 'cluster-id');
$producer->flush(10000);
report('flushed producer', $producer);

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$lowLevel = new Consumer($conf);
$lowLevel->getMetadata(true, null, 10000);
report('low-level consumer', $lowLevel);

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$conf->set('group.id', 'cluster-id');
$conf->set('auto.offset.reset', 'earliest');
$conf->set('enable.auto.commit', 'false');
$conf->setOffsetCommitCb(function (KafkaConsumer $kafka, int $err, array $partitions) {
    report('offset commit callback', $kafka);
});
$consumer = new KafkaConsumer($conf);
$consumer->subscribe([$topicName]);
$deadline = microtime(true) + 20;
while (microtime(true) < $deadline) {
    $message = $consumer->consume(1000);
    if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
        report('consumer', $consumer);
        $consumer->commit($message);
        // Serves the offset commit callback.
        $consumer->consume(100);
        break;
    }
}
$consumer->close();
report('closed consumer', $consumer);

report('other object', new stdClass());
