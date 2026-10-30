<?php

// Produces to brokers that never answer, so the producer never has a cluster id, and prints how
// long each produce took, in milliseconds.
$conf = new RdKafka\Conf();
$conf->set('bootstrap.servers', '127.0.0.1:1');
$producer = new RdKafka\Producer($conf);
$topic = $producer->newTopic($argv[1]);
$times = [];
for ($i = 0; $i < 5; $i++) {
    $start = microtime(true);
    $topic->producev(RD_KAFKA_PARTITION_UA, 0, "message $i");
    $times[] = (int)round((microtime(true) - $start) * 1000);
}
echo 'produce times: ', implode(' ', $times), PHP_EOL;
// Drop what can never be delivered, rather than wait for it when the producer is destroyed.
$producer->purge(RD_KAFKA_PURGE_F_QUEUE);
