<?php

declare(strict_types=1);

use DDTrace\Integrations\Kafka\KafkaIntegration;
use DDTrace\Util\ObjectKVStore;
use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\KafkaConsumer;

// Sets consume checkpoints for more topics than a consumer keeps the tags of, twice over, with a
// KafkaConsumer ("consume") or a low-level consumer's queue ("queue"). Prints how many checkpoints
// it set and how many had tags not their topic's, and the most topics the consumer kept at once.
$group = "dsm-tags-cap-$argv[2]";
$conf = new Conf();
$conf->set('bootstrap.servers', 'kafka-integration:9092');
$conf->set('group.id', $group);
if ($argv[1] === 'consume') {
    $client = $consumer = new KafkaConsumer($conf);
} else {
    $client = new Consumer($conf);
    $consumer = $client->newQueue();
}
// Gives the client its cluster id.
$client->getMetadata(true, null, 10000);
$clusterId = \DDTrace\Internal\kafka_cluster_id($client);

$expected = null;
$wrong = [];
$checkpoints = 0;
$checkTags = function (\DDTrace\HookData $hook) use (&$expected, &$wrong, &$checkpoints) {
    $checkpoints++;
    $tags = $hook->args[0];
    sort($tags);
    if ($tags !== $expected) {
        $wrong[] = implode(',', $tags);
    }
};
\DDTrace\install_hook('DDTrace\Internal\dsm_set_checkpoint', $checkTags);

$consumeTags = new ReflectionProperty(KafkaIntegration::class, 'consumeTags');
$consumeTags->setAccessible(true);
$kept = 0;
$topics = 2 * KafkaIntegration::DSM_CONSUME_TAGS_TOPICS + 1;
for ($i = 0; $i < 2 * $topics; $i++) {
    $topic = 'test-dsm-tags-cap-' . ($i % $topics);
    $expected = ['direction:in', "group:$group", "kafka_cluster_id:$clusterId", "topic:$topic", 'type:kafka'];
    $message = new RdKafka\Message();
    $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;
    $message->topic_name = $topic;
    $message->payload = 'payload';
    KafkaIntegration::setDataStreamsConsumeCheckpoint($message, $consumer);
    $map = $consumeTags->getValue();
    $entry = $map ? $map[$consumer] : ObjectKVStore::get($consumer, 'dsm_consume_tags');
    $kept = max($kept, count($entry[1]));
}

echo 'cluster id: ', var_export($clusterId, true), PHP_EOL;
echo "checkpoints: $checkpoints", PHP_EOL;
echo 'wrong: ', count($wrong), $wrong ? ", first: $wrong[0]" : '', PHP_EOL;
echo "kept at most: $kept", PHP_EOL;
