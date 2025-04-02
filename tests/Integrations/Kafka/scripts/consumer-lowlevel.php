<?php

declare(strict_types=1);

use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\TopicConf;


$conf = new Conf();
$conf->set('bootstrap.servers', 'kafka_integration:9092');
$conf->set('group.id', 'consumer-lowlevel');
$conf->set('enable.partition.eof', 'true');
//$conf->set('log_level', (string) LOG_DEBUG);
//$conf->set('debug', 'all');
$conf->setLogCb(
    function (Consumer $consumer, int $level, string $facility, string $message): void {
        echo sprintf('  log: %d %s %s', $level, $facility, $message) . PHP_EOL;
    }
);

$conf->set('statistics.interval.ms', (string) 1000);
$conf->setStatsCb(
    function (Consumer $consumer, string $json, int $jsonLength, $opaque = null): void {
        echo "stats: ${json}" . PHP_EOL;
    }
);

$topicConf = new TopicConf();
$topicConf->set('enable.auto.commit', 'true');
$topicConf->set('auto.commit.interval.ms', (string) 100);
$topicConf->set('auto.offset.reset', 'earliest');
//var_dump($topicConf->dump());

$consumer = new Consumer($conf);

$topic = $consumer->newTopic('test-lowlevel', $topicConf);
//var_dump($topic);

$queue = $consumer->newQueue();
$offset = $argv[1] ?? RD_KAFKA_OFFSET_BEGINNING;
$topic->consumeQueueStart(0, (int) $offset, $queue);
//$topic->consumeQueueStart(1, RD_KAFKA_OFFSET_BEGINNING, $queue);
//$topic->consumeQueueStart(2, RD_KAFKA_OFFSET_BEGINNING, $queue);

$messageCount = 0;
$expectedMessages = 3; // We expect 3 messages based on the test snapshots
$startTime = microtime(true);
$timeout = 10; // 10 seconds timeout

do {
    $message = $queue->consume(5000);
    if ($message === null) {
        break;
    }
    echo sprintf('consume msg: %s, timestamp: %s, topic: %s', $message->payload, $message->timestamp, $message->topic_name) . PHP_EOL;
    $messageCount++;

    // triggers log output
    $events = $consumer->poll(1);
    echo sprintf('polling triggered %d events', $events) . PHP_EOL;

    if ($messageCount >= $expectedMessages) {
        echo "Processed all expected messages. Exiting...\n";
        break;
    }

    // Check timeout
    if (microtime(true) - $startTime > $timeout) {
        echo "Timeout reached after {$timeout} seconds. Exiting...\n";
        break;
    }
} while (true);

$topic->consumeStop(0);
//$topic->consumeStop(1);
//$topic->consumeStop(2);
