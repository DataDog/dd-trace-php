<?php

declare(strict_types=1);

use RdKafka\Producer;

$topicName = $argv[1];
$brokers = $argv[2] ?? 'kafka-integration:9092';

// Prints the backlogs the integration records, under the producer that recorded them.
$case = null;
\DDTrace\install_hook('DDTrace\\Internal\\dsm_track_backlog', function (\DDTrace\HookData $hook) use (&$case) {
    echo sprintf("%s: %s\n", $case, implode(',', $hook->args[0]));
});

// Without a Conf, php-rdkafka gives a Producer librdkafka's defaults, and the brokers are added after.
foreach (['no conf' => new Producer(), 'null conf' => new Producer(null)] as $case => $producer) {
    $producer->addBrokers($brokers);
    $producer->newTopic($topicName)->producev(RD_KAFKA_PARTITION_UA, 0, $case);
    $producer->flush(10000);
}
