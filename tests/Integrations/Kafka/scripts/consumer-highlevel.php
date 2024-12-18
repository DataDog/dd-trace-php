<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\KafkaConsumer;

$conf = new Conf();
$conf->set('bootstrap.servers', 'kafka_integration:9092');
$conf->set('group.id', 'consumer-highlevel');
$conf->set('enable.partition.eof', 'true');
$conf->set('auto.offset.reset', 'earliest');

// Track partitions that have been fully consumed
$partitionsEof = [];

$consumer = new KafkaConsumer($conf);
$consumer->subscribe(['test-highlevel']);

echo "Consumer started, waiting for messages...\n";

$message = $consumer->consume(5000);

// Process the message
echo sprintf("Message consumed: %s\n", $message->payload);
// Headers
echo sprintf("Headers: %s\n", json_encode($message->headers));
// Commit the message offset after processing it
$consumer->commit($message);


$consumer->close();
