<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;

// Produces a message with the application's headers and one without, consumes both, and prints
// the payload size of each checkpoint and the headers each message arrived with, in order.
$run = $argv[1];
$topicName = "test-dsm-headers-$run";
$brokers = 'kafka-integration:9092';

\DDTrace\install_hook('DDTrace\Internal\dsm_set_checkpoint', function (\DDTrace\HookData $hook) {
    echo sprintf("%s size: %d\n", in_array('direction:out', $hook->args[0], true) ? 'produce' : 'consume', $hook->args[2]);
});

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$producer = new Producer($conf);
$topic = $producer->newTopic($topicName);
// Creates the topic.
$producer->getMetadata(false, $topic, 10000);
// php-rdkafka sends no header with a null value, and none from the first integer name on.
$topic->producev(RD_KAFKA_PARTITION_UA, 0, 'with headers', 'key', [
    'x-datadog-trace-id' => '42',
    'empty' => null,
    'dd-pathway-ctx-base64' => 'application context',
    'user' => 'value',
]);
$topic->producev(RD_KAFKA_PARTITION_UA, 0, 'without headers');
$producer->flush(10000);

$conf = new Conf();
$conf->set('bootstrap.servers', $brokers);
$conf->set('group.id', "dsm-headers-$run");
$conf->set('auto.offset.reset', 'earliest');
$consumer = new KafkaConsumer($conf);
$consumer->subscribe([$topicName]);
$received = 0;
$deadline = microtime(true) + 20;
while ($received < 2 && microtime(true) < $deadline) {
    $message = $consumer->consume(1000);
    if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
        $received++;
        echo sprintf("%s: %s\n", $message->payload, json_encode($message->headers));
    }
}
$consumer->close();
