<?php

// Kafka produce/consume throughput workload for DSM overhead benchmarking.
//
// PHP port of the Ruby `kafka_throughput.rb` / Java `Workload.java` used by the
// `ruby/data-streams-monitoring` and `java/data-streams-monitoring`
// benchmarking-platform branches (all three ported from .NET's
// `dd-trace-dotnet/data-streams-monitoring`). The message-level work mirrors
// them so the languages' DSM overhead numbers are broadly comparable.
// Divergences to state whenever comparing across languages:
//
//   * thread count -- always 1. The PHP CLI has no threads, so NUM_THREADS is
//     rejected unless it is 1 (Ruby/Java/Python default to 1, .NET uses 5);
//   * process model -- like Ruby/Java/Python, all iterations run in one
//     process with the tracer already initialized, unlike .NET's
//     per-iteration relaunch under TimeItSharp;
//   * client -- php-rdkafka over librdkafka, the client dd-trace-php's Kafka
//     integration instruments (src/DDTrace/Integrations/Kafka);
//   * teardown -- the consumer is released rather than close()d (see
//     run_worker), working around a php-rdkafka 6.0.5 leak.
//
// Tracing/DSM are controlled purely by environment (the ddtrace extension's
// INI being scanned or not, and DD_DATA_STREAMS_ENABLED, see run_timeit.php);
// this file contains no tracer-specific code, so the DSM-on and DSM-off
// experiments run the exact same code.

require_once __DIR__ . '/common.php';

function bootstrap_servers(): string
{
    return getenv('KAFKA_BOOTSTRAP_SERVERS') ?: 'localhost:9092';
}

// Asks the broker for $topic's metadata until it has a partition with a
// leader. Kafka's default auto.create.topics.enable creates the topic (with
// server.properties' num.partitions=1) on the first request, so this keeps
// topic creation, and the producer's first metadata lookup, out of the timed
// produce phase.
function create_topic(RdKafka\Producer $producer, RdKafka\ProducerTopic $topic): void
{
    $deadline = now_ms() + TIMEOUT_MS;
    while (true) {
        try {
            $metadata = $producer->getMetadata(false, $topic, 5000);
            foreach ($metadata->getTopics() as $topicMetadata) {
                if ($topicMetadata->getErr() === RD_KAFKA_RESP_ERR_NO_ERROR) {
                    foreach ($topicMetadata->getPartitions() as $partition) {
                        if ($partition->getLeader() >= 0) {
                            return;
                        }
                    }
                }
            }
        } catch (RdKafka\Exception $e) {
            // Retried below, until the deadline.
        }
        if (now_ms() > $deadline) {
            throw new RuntimeException("Topic {$topic->getName()} was not created within " . TIMEOUT_MS . ' ms');
        }
        usleep(10000);
    }
}

// Consumes until the subscribed consumer has joined its group, been assigned
// the partition and fetched to its (empty) end.
function wait_for_assignment(RdKafka\KafkaConsumer $consumer, string $topicName, bool &$assigned): void
{
    $deadline = now_ms() + TIMEOUT_MS;
    while (true) {
        $message = $consumer->consume(100);
        if ($assigned && $message !== null && $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            return;
        }
        if ($message !== null && $message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException("Unexpected message on topic $topicName during consumer setup");
        }
        if ($message !== null && $message->err !== RD_KAFKA_RESP_ERR__TIMED_OUT
                && $message->err !== RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            throw new RuntimeException("Consumer setup failed on topic $topicName: " . $message->errstr());
        }
        if (now_ms() > $deadline) {
            throw new RuntimeException("Consumer was not assigned topic $topicName and at its end within "
                . TIMEOUT_MS . ' ms');
        }
    }
}

function run_worker(string $topicName, string $groupId, int $commitEvery): array
{
    $payload = str_repeat('x', PAYLOAD_SIZE);
    $headers = [];
    for ($j = 0; $j < NUM_HEADERS; $j++) {
        $headers["key$j"] = "value$j";
    }

    // Setup: not timed. Producer/consumer construction, topic creation, and
    // the consumer's group join and assignment all happen before
    // produce_start, so they don't land in produce_ms/consume_ms.
    $producerConf = new RdKafka\Conf();
    $producerConf->set('bootstrap.servers', bootstrap_servers());
    $producerConf->set('client.id', 'dsm-benchmark');
    $producerConf->set('acks', 'all');
    $producer = new RdKafka\Producer($producerConf);
    $topic = $producer->newTopic($topicName);
    create_topic($producer, $topic);

    $consumerConf = new RdKafka\Conf();
    $consumerConf->set('bootstrap.servers', bootstrap_servers());
    $consumerConf->set('client.id', 'dsm-benchmark');
    $consumerConf->set('group.id', $groupId);
    // The explicit commits below are the commit policy.
    $consumerConf->set('enable.auto.commit', 'false');
    // The group is fresh (run_benchmark), so it starts at the end of the
    // still-empty partition.
    $consumerConf->set('auto.offset.reset', 'latest');
    // librdkafka's default is 500 ms, which a fetch that finds no data waits
    // out on the broker. Return as soon as any data is available so the timing
    // reflects per-message work (the Ruby port's max_wait_time 0.01 for the
    // same reason).
    $consumerConf->set('fetch.wait.max.ms', '10');
    // Reports reaching the end of the partition, which is how setup knows the
    // consumer is ready (below).
    $consumerConf->set('enable.partition.eof', 'true');
    $assigned = false;
    $consumerConf->setRebalanceCb(static function (
        RdKafka\KafkaConsumer $kafka,
        int $err,
        ?array $partitions = null
    ) use (&$assigned) {
        if ($err === RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS) {
            $kafka->assign($partitions);
            $assigned = true;
        } else {
            $kafka->assign(null);
            $assigned = false;
        }
    });
    $consumer = new RdKafka\KafkaConsumer($consumerConf);
    $consumer->subscribe([$topicName]);
    // Joining the group, resolving the starting offset and the first fetch
    // happen in the background. Wait for them here rather than ahead of the
    // first message in the timed consume phase.
    wait_for_assignment($consumer, $topicName, $assigned);

    try {
        // Phase 1: produce all messages.
        $produceStart = now_ms();
        for ($i = 0; $i < MESSAGE_COUNT; $i++) {
            $topic->producev(RD_KAFKA_PARTITION_UA, 0, $payload, null, $headers);
            $producer->poll(0);
        }
        $err = $producer->flush(TIMEOUT_MS);
        if ($err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException("Producer flush failed on topic $topicName: " . rd_kafka_err2str($err));
        }
        $produceMs = now_ms() - $produceStart;

        // Phase 2: consume all messages, committing synchronously every
        // $commitEvery messages and after the last one. commit($message)
        // commits that message's offset + 1.
        $consumed = 0;
        $consumeStart = now_ms();
        $deadline = $consumeStart + TIMEOUT_MS;
        while ($consumed < MESSAGE_COUNT) {
            $message = $consumer->consume(1000);
            if ($message === null || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT
                    || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                if (now_ms() > $deadline) {
                    break;
                }
                continue;
            }
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                throw new RuntimeException("Consume failed on topic $topicName: " . $message->errstr());
            }
            $consumed++;
            if ($consumed >= MESSAGE_COUNT || $consumed % $commitEvery === 0) {
                $consumer->commit($message);
            }
        }
        $consumeMs = now_ms() - $consumeStart;

        if ($consumed !== MESSAGE_COUNT) {
            throw new RuntimeException("Consumed $consumed/" . MESSAGE_COUNT . " messages on topic $topicName");
        }
    } finally {
        // Teardown: not timed. Releasing the consumer closes and destroys it.
        // Not close(): in php-rdkafka 6.0.5 it closes the consumer but never
        // destroys it (KafkaConsumer::close() drops the handle without
        // rd_kafka_destroy(), so the free handler can't either), leaking its
        // threads every iteration. They outlive PHP's shutdown, which unloads
        // librdkafka under them, and the process segfaults at exit.
        $consumer = null;
    }

    return ['produce_ms' => $produceMs, 'consume_ms' => $consumeMs];
}

// Runs one full produce/consume pass.
//
// A per-process nonce and $runId make the topic and its consumer group fresh
// per iteration, across experiments and reruns against the same broker.
function run_benchmark(int $runId, int $commitEvery): array
{
    static $nonce = null;
    $nonce ??= bin2hex(random_bytes(4));
    check_num_threads();
    $baseTopic = getenv('KAFKA_TOPIC') ?: 'benchmark-topic';
    $topicName = "$baseTopic-$nonce-$runId";

    return run_worker($topicName, "$topicName-group", $commitEvery);
}
