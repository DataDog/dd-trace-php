<?php

namespace DDTrace\Integrations\Kafka;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanLink;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class KafkaIntegration extends Integration
{
    const NAME = 'kafka';

    // Header carrying the Data Streams Monitoring pathway context.
    const DSM_CONTEXT_HEADER = 'dd-pathway-ctx-base64';

    // Set while the integration installs its own callback on a Conf, so that the hooks on the
    // setters do not wrap it a second time.
    private static $installingDataStreamsCallback = false;

    const METADATA_MAPPING = [
        'metadata.broker.list' => Tag::KAFKA_HOST_LIST,
        'group.id' => Tag::KAFKA_GROUP_ID,
        'client.id' => Tag::KAFKA_CLIENT_ID
    ];

    public static function init(): int
    {
        if (strtok(phpversion('rdkafka'), '.') < 6) {
            return Integration::NOT_LOADED;
        }

        self::installProducerTopicHooks();
        self::installConsumerHooks();
        self::installConfigurationHooks();
        self::installDataStreamsCallbackHooks();

        return Integration::LOADED;
    }

    private static function installProducerTopicHooks()
    {
        \DDTrace\install_hook(
            'RdKafka\ProducerTopic::producev',
            static function (HookData $hook) {
                /** @var \RdKafka\ProducerTopic $this */
                KafkaIntegration::setupKafkaProduceSpan($hook, $hook->instance);
            }
        );
    }

    public static function setupKafkaProduceSpan(HookData $hook, \RdKafka\ProducerTopic $producerTopic)
    {
        $span = $hook->span();
        self::setupCommonSpanMetadata($span, Tag::KAFKA_PRODUCE, Tag::SPAN_KIND_VALUE_PRODUCER, Tag::MQ_OPERATION_SEND);

        $span->meta[Tag::MQ_DESTINATION] = $producerTopic->getName();
        $span->meta[Tag::MQ_DESTINATION_KIND] = Type::QUEUE;

        $conf = ObjectKVStore::get($producerTopic, 'conf');
        self::addProducerSpanMetadata($span, $conf, $hook->args);

        $headers = [];
        $inject = \ddtrace_config_distributed_tracing_enabled();
        if ($inject) {
            $headers = \DDTrace\generate_distributed_tracing_headers();
        }
        $context = self::setDataStreamsProduceCheckpoint($producerTopic, $hook->args, $headers);
        if ($context !== null) {
            $headers[self::DSM_CONTEXT_HEADER] = $context;
            $inject = true;
        }
        if ($inject) {
            $hook->args = self::injectHeadersIntoArgs($hook->args, $headers);
            $hook->overrideArguments($hook->args);
        }
    }

    /**
     * @return string|null the pathway context to send with the message, or null when DSM is off
     */
    public static function setDataStreamsProduceCheckpoint(\RdKafka\ProducerTopic $producerTopic, array $args, array $headers)
    {
        if (!\dd_trace_env_config('DD_DATA_STREAMS_ENABLED')) {
            return null;
        }

        // The message as it is sent, but for the context header the checkpoint computes.
        $payloadSize = strlen($args[2] ?? '') + strlen($args[3] ?? '')
            + self::headersSize(array_merge($args[4] ?? [], $headers));
        return \DDTrace\Internal\dsm_set_checkpoint([
            'direction:out',
            'topic:' . $producerTopic->getName(),
            'type:kafka',
        ], null, $payloadSize);
    }

    public static function addProducerSpanMetadata($span, $conf, $args)
    {
        self::addMetadataToSpan($span, $conf);
        $span->metrics[Tag::KAFKA_PARTITION] = $args[0];
        $span->metrics[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($args[2]);
        if (isset($args[3])) {
            $span->meta[Tag::KAFKA_MESSAGE_KEY] = $args[3];
        }
    }

    private static function injectHeadersIntoArgs(array $args, array $headers): array
    {
        // public RdKafka\ProducerTopic::producev (
        //      integer $partition ,
        //      integer $msgflags ,
        //      string $payload [,
        //      string $key = NULL [,
        //      array $headers = NULL [,
        //      integer $timestamp_ms = NULL [,
        //      string $opaque = NULL
        // ]]]] ) : void
        $argsCount = count($args);
        if ($argsCount >= 5) {
            $args[4] = array_merge($args[4] ?? [], $headers);
        } elseif ($argsCount === 4) {
            $args[] = $headers;
        } elseif ($argsCount === 3) {
            $args[] = null;  // $key
            $args[] = $headers;
        }
        return $args;
    }

    private static function installConsumerHooks()
    {
        $consumerMethods = [
            'RdKafka\KafkaConsumer::consume',
            'RdKafka\Queue::consume'
        ];

        foreach ($consumerMethods as $method) {
            \DDTrace\install_hook(
                $method,
                static function (HookData $hook) {
                    $hook->data['start'] = microtime(true);
                },
                static function (HookData $hook) {
                    /** @var \RdKafka\Message $message */
                    $message = $hook->returned;

                    if ($message) {
                        if ($message->headers && $link = SpanLink::fromHeaders($message->headers)) {
                            if (\dd_trace_env_config('DD_TRACE_KAFKA_DISTRIBUTED_TRACING')) {
                                $span = \DDTrace\start_trace_span($hook->data['start']);
                                \DDTrace\consume_distributed_tracing_headers($message->headers);
                            } else {
                                $span = \DDTrace\start_span($hook->data['start']);
                                $span->links[] = $link;
                            }
                        } else {
                            $span = \DDTrace\start_span($hook->data['start']);
                        }

                        $span->meta[Tag::MQ_DESTINATION] = $message->topic_name;
                        $span->meta[Tag::MQ_DESTINATION_KIND] = Type::QUEUE;
                        $span->metrics[Tag::KAFKA_PARTITION] = $message->partition;
                        $span->metrics[Tag::KAFKA_MESSAGE_OFFSET] = $message->offset;
                        $span->metrics[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->payload ?? '');
                    } else {
                        $span = \DDTrace\start_span($hook->data['start']);
                    }

                    if (!$message || $message->payload === null || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                        $span->meta[Tag::KAFKA_TOMBSTONE] = true;
                    }

                    $hook->data['span'] = $span;
                    KafkaIntegration::setupKafkaConsumeSpan($hook, $hook->instance);
                    if ($message) {
                        KafkaIntegration::setDataStreamsConsumeCheckpoint($message, $hook->instance);
                    }
                    \DDTrace\collect_code_origins(1);
                    \DDTrace\close_span();
                }
            );
        }
    }

    public static function setupKafkaConsumeSpan(HookData $hook, $consumer)
    {
        $span = $hook->data['span'];
        self::setupCommonSpanMetadata($span, Tag::KAFKA_CONSUME, Tag::SPAN_KIND_VALUE_CONSUMER, Tag::MQ_OPERATION_RECEIVE);

        $conf = ObjectKVStore::get($consumer, 'conf');
        self::addMetadataToSpan($span, $conf);
    }

    public static function setDataStreamsConsumeCheckpoint(\RdKafka\Message $message, $consumer)
    {
        if (!\dd_trace_env_config('DD_DATA_STREAMS_ENABLED') || $message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            return;
        }

        $edgeTags = ['direction:in'];
        $conf = ObjectKVStore::get($consumer, 'conf');
        if (isset($conf['group.id'])) {
            $edgeTags[] = 'group:' . $conf['group.id'];
        }
        $edgeTags[] = 'topic:' . $message->topic_name;
        $edgeTags[] = 'type:kafka';

        \DDTrace\Internal\dsm_set_checkpoint(
            $edgeTags,
            $message->headers[self::DSM_CONTEXT_HEADER] ?? null,
            strlen($message->payload ?? '') + strlen($message->key ?? '') + self::headersSize($message->headers ?? [])
        );
    }

    // Bytes of the headers' names and values, as the Java tracer counts them.
    private static function headersSize($headers): int
    {
        $size = 0;
        foreach ((array) $headers as $name => $value) {
            $size += strlen((string) $name) + strlen((string) $value);
        }
        return $size;
    }

    /**
     * librdkafka reports the offset a message was produced at only to a delivery report
     * callback, and the offsets a consumer group commits - automatically or not - only to an
     * offset commit callback. The integration wraps the application's callbacks, and installs
     * its own where there are none, to record these as Data Streams Monitoring backlogs.
     */
    private static function installDataStreamsCallbackHooks()
    {
        $wrapCallback = static function ($wrap) {
            return static function (HookData $hook) use ($wrap) {
                if (KafkaIntegration::$installingDataStreamsCallback
                        || !\dd_trace_env_config('DD_DATA_STREAMS_ENABLED')
                        || !isset($hook->args[0])) {
                    return;
                }
                ObjectKVStore::put($hook->instance, $wrap, true);
                $hook->args[0] = KafkaIntegration::$wrap($hook->args[0]);
                $hook->overrideArguments($hook->args);
            };
        };
        \DDTrace\install_hook('RdKafka\Conf::setDrMsgCb', $wrapCallback('trackProduceOffsets'));
        \DDTrace\install_hook('RdKafka\Conf::setOffsetCommitCb', $wrapCallback('trackCommitOffsets'));
    }

    /**
     * Give `$conf` the integration's own `$wrap` callback, unless the application has set one,
     * which is already wrapped.
     */
    public static function installDataStreamsCallback($conf, string $setter, string $wrap)
    {
        if (!$conf instanceof \RdKafka\Conf
                || !\dd_trace_env_config('DD_DATA_STREAMS_ENABLED')
                || ObjectKVStore::get($conf, $wrap, false)) {
            return;
        }
        ObjectKVStore::put($conf, $wrap, true);
        self::$installingDataStreamsCallback = true;
        try {
            $conf->$setter(self::$wrap(null));
        } finally {
            self::$installingDataStreamsCallback = false;
        }
    }

    /** A delivery report callback recording produce offsets, then calling `$callback`. */
    public static function trackProduceOffsets($callback)
    {
        return static function (...$args) use ($callback) {
            list($kafka, $message) = $args + [null, null];
            if ($message instanceof \RdKafka\Message && $message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                try {
                    \DDTrace\Internal\dsm_track_backlog(array_merge([
                        'topic:' . $message->topic_name,
                        'type:kafka_produce',
                        'partition:' . $message->partition,
                    ], self::clusterIdTags($kafka)), $message->offset);
                } catch (\Throwable $e) {
                }
            }
            if ($callback !== null) {
                return $callback(...$args);
            }
        };
    }

    /** An offset commit callback recording committed offsets, then calling `$callback`. */
    public static function trackCommitOffsets($callback)
    {
        return static function (...$args) use ($callback) {
            list($kafka, $err, $partitions) = $args + [null, null, null];
            if ($err === RD_KAFKA_RESP_ERR_NO_ERROR && is_array($partitions)) {
                try {
                    $conf = $kafka ? ObjectKVStore::get($kafka, 'conf') : null;
                    $group = 'consumer_group:' . ($conf['group.id'] ?? '');
                    $cluster = self::clusterIdTags($kafka);
                    foreach ($partitions as $partition) {
                        if (!$partition instanceof \RdKafka\TopicPartition
                                || (method_exists($partition, 'getErr') && $partition->getErr())) {
                            continue;
                        }
                        \DDTrace\Internal\dsm_track_backlog(array_merge([
                            'topic:' . $partition->getTopic(),
                            'type:kafka_commit',
                            $group,
                            'partition:' . $partition->getPartition(),
                        ], $cluster), $partition->getOffset());
                    }
                } catch (\Throwable $e) {
                }
            }
            if ($callback !== null) {
                return $callback(...$args);
            }
        };
    }

    /**
     * The tag naming the cluster `$kafka` is connected to, from librdkafka's last metadata
     * response. Delivery reports and commits only come after one, so callbacks always have it.
     * Anything but a client has none: kafka_cluster_id() would throw, losing the backlog with it.
     */
    private static function clusterIdTags($kafka): array
    {
        if (!$kafka instanceof \RdKafka && !$kafka instanceof \RdKafka\KafkaConsumer) {
            return [];
        }
        $clusterId = \DDTrace\Internal\kafka_cluster_id($kafka);
        return $clusterId === null ? [] : ['kafka_cluster_id:' . $clusterId];
    }

    private static function addMetadataToSpan($span, $conf)
    {
        foreach (self::METADATA_MAPPING as $configKey => $tagKey) {
            if (isset($conf[$configKey])) {
                $span->meta[$tagKey] = $conf[$configKey];
            }
        }
    }

    public static function setupCommonSpanMetadata($span, string $name, string $spanKind, string $operation)
    {
        $span->name = $name;
        $span->type = Type::QUEUE;
        $span->meta[Tag::SPAN_KIND] = $spanKind;
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::MQ_SYSTEM] = self::NAME;
        $span->meta[Tag::MQ_OPERATION] = $operation;
    }

    private static function installConfigurationHooks()
    {
        $configurationHooks = [
            'RdKafka\KafkaConsumer' => ['__construct'],
            'RdKafka\Producer' => ['__construct', 'newTopic'],
            'RdKafka\Consumer' => ['__construct', 'newQueue']
        ];

        foreach ($configurationHooks as $class => $methods) {
            foreach ($methods as $method) {
                self::installConfigurationHook($class, $method);
            }
        }
    }

    private static function installConfigurationHook(string $class, string $method)
    {
        \DDTrace\install_hook(
            "$class::$method",
            static function (HookData $hook) use ($class, $method) {
                if ($method === '__construct') {
                    $conf = $hook->args[0] ?? null;
                    // A Producer or Consumer constructed without a Conf gets librdkafka's
                    // defaults, which an empty Conf has too. Pass one, for the integration to
                    // read and to put its callbacks on. A KafkaConsumer requires a Conf: passing
                    // one would replace the error about the missing Conf with one about group.id.
                    if ($conf === null && $class !== 'RdKafka\KafkaConsumer') {
                        $conf = new \RdKafka\Conf();
                        $hook->overrideArguments([$conf]);
                    }
                    ObjectKVStore::put($hook->instance, 'conf', $conf->dump());
                    // The constructor copies the Conf, so callbacks must be on it beforehand. A
                    // low-level Consumer gets none of its own: it serves commit results only
                    // when the application polls it, which it need not do.
                    if ($class === 'RdKafka\Producer') {
                        KafkaIntegration::installDataStreamsCallback($conf, 'setDrMsgCb', 'trackProduceOffsets');
                    } elseif ($class === 'RdKafka\KafkaConsumer') {
                        KafkaIntegration::installDataStreamsCallback($conf, 'setOffsetCommitCb', 'trackCommitOffsets');
                    }
                }
            },
            static function (HookData $hook) use ($method) {
                if (in_array($method, ['newTopic', 'newQueue'])) {
                    $conf = ObjectKVStore::get($hook->instance, 'conf');
                    ObjectKVStore::put($hook->returned, 'conf', $conf);
                }
            }
        );
    }
}
