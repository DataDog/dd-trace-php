<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\KafkaConsumer;

$conf = new Conf();
$conf->set('bootstrap.servers', 'kafka-integration:9092');
$conf->set('group.id', 'consumer-dsm');
$conf->set('auto.offset.reset', 'earliest');

$consumer = new KafkaConsumer($conf);
$consumer->subscribe([$argv[1]]);

$deadline = microtime(true) + 10;
while (microtime(true) < $deadline) {
    $message = $consumer->consume(1000);
    if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
        echo sprintf("dsm-context: %s\n", $message->headers['dd-pathway-ctx-base64'] ?? '');
        $consumer->commit($message);
        break;
    }
}

$consumer->close();
