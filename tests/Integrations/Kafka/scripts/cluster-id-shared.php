<?php

// "learn" reads the cluster id from a producer that has had its brokers' answer, which shares it
// through the sidecar. "read" gets it for a producer whose brokers never answer, from the id
// shared for the same bootstrap servers.
$brokers = 'kafka-integration:9092';
$conf = new RdKafka\Conf();
if ($argv[1] === 'learn') {
    $conf->set('bootstrap.servers', $brokers);
    $producer = new RdKafka\Producer($conf);
    $producer->getMetadata(true, null, 10000);
} else {
    $conf->set('bootstrap.servers', '127.0.0.1:1');
    $producer = new RdKafka\Producer($conf);
}

// The sidecar publishes what a process shares shortly after.
for ($deadline = microtime(true) + 5;
     ($clusterId = \DDTrace\Internal\kafka_cluster_id($producer, $brokers)) === null && microtime(true) < $deadline;) {
    usleep(50000);
}
echo sprintf("%s: %s\n", $argv[1], var_export($clusterId, true));
