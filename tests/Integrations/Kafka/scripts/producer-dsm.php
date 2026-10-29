<?php

require __DIR__ . '/print-dsm-checkpoints.php';

$conf = new RdKafka\Conf();
$conf->set('bootstrap.servers', 'kafka-integration:9092');
// The integration wraps the application's delivery report callback; it still runs.
$conf->setDrMsgCb(function ($kafka, $message) {
    echo 'delivered: ', var_export($message->err === RD_KAFKA_RESP_ERR_NO_ERROR, true), PHP_EOL;
});
$producer = new RdKafka\Producer($conf);
$topic = $producer->newTopic($argv[1]);
// So that the producer has the cluster id from its brokers' answer before it produces.
$producer->getMetadata(false, $topic, 10000);
$topic->producev(RD_KAFKA_PARTITION_UA, 0, 'dsm');
$producer->flush(10000);
