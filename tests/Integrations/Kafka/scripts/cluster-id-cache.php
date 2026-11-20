<?php

// Prints how many times the integration read each producer's cluster id from librdkafka, for how
// many produces, and how many of their checkpoints were tagged with one.
$reads = [];
\DDTrace\install_hook('DDTrace\Internal\kafka_cluster_id', function (\DDTrace\HookData $hook) use (&$reads) {
    $client = spl_object_hash($hook->args[0]);
    $reads[$client] = ($reads[$client] ?? 0) + 1;
});
$tagged = 0;
\DDTrace\install_hook('DDTrace\Internal\dsm_set_checkpoint', function (\DDTrace\HookData $hook) use (&$tagged) {
    $tagged += count(preg_grep('/^kafka_cluster_id:/', $hook->args[0]));
});

$topicName = $argv[1];
$clients = [
    'connected' => 'kafka-integration:9092',
    // The same cluster, for a client of its own.
    'other bootstrap servers' => 'kafka-integration:9092,127.0.0.1:1',
    // Never has a cluster id.
    'unreachable' => '127.0.0.1:1',
];
$producers = [];
foreach ($clients as $name => $brokers) {
    $conf = new RdKafka\Conf();
    $conf->set('bootstrap.servers', $brokers);
    $producer = new RdKafka\Producer($conf);
    $topic = $producer->newTopic($topicName);
    if ($name !== 'unreachable') {
        $producer->getMetadata(false, $topic, 10000);
    }
    $producers[$name] = [$producer, $topic];
}

function produce($name, $count, $label = null)
{
    global $producers, $reads, $tagged;
    list($producer, $topic) = $producers[$name];
    $client = spl_object_hash($producer);
    $readsBefore = $reads[$client] ?? 0;
    $taggedBefore = $tagged;
    for ($i = 0; $i < $count; $i++) {
        $topic->producev(RD_KAFKA_PARTITION_UA, 0, "$name $i");
    }
    echo sprintf(
        "%s: %d reads for %d produces, %d tagged\n",
        $label ?? $name,
        ($reads[$client] ?? 0) - $readsBefore,
        $count,
        $tagged - $taggedBefore
    );
}

produce('connected', 5);
produce('other bootstrap servers', 5);
produce('unreachable', 5);
// The cached id expires.
usleep(1100000);
produce('connected', 1, 'connected after expiry');

$producers['unreachable'][0]->purge(RD_KAFKA_PURGE_F_QUEUE);
$producers['connected'][0]->flush(10000);
$producers['other bootstrap servers'][0]->flush(10000);
