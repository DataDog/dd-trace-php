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

    const METADATA_MAPPING = [
        'metadata.broker.list' => Tag::KAFKA_HOST_LIST,
        'group.id' => Tag::KAFKA_GROUP_ID,
        'client.id' => Tag::KAFKA_CLIENT_ID
    ];

    public function init(): int
    {
        $this->installProducerTopicHooks();
        $this->installConsumerHooks();
        $this->installConfigurationHooks();

        return Integration::LOADED;
    }

    private function installProducerTopicHooks()
    {
        $integration = $this;

        $hooks = [
            //'RdKafka\ProducerTopic::produce',
            'RdKafka\ProducerTopic::producev'
        ];

        foreach ($hooks as $hookMethod) {
            \DDTrace\install_hook(
                $hookMethod,
                function (HookData $hook) use ($integration) {
                    /** @var \RdKafka\ProducerTopic $this */
                    $integration->setupKafkaProduceSpan($hook, $this);
                }
            );
        }
    }

    public function setupKafkaProduceSpan(HookData $hook, \RdKafka\ProducerTopic $producerTopic)
    {
        /** @var \RdKafka\ProducerTopic $this */
        $span = $hook->span();
        KafkaIntegration::setupCommonSpanMetadata($span, Tag::KAFKA_PRODUCE, Tag::SPAN_KIND_VALUE_PRODUCER, Tag::MQ_OPERATION_SEND);

        $span->meta[Tag::MQ_DESTINATION] = $producerTopic->getName();
        $span->meta[Tag::MQ_DESTINATION_KIND] = Type::QUEUE;

        $conf = ObjectKVStore::get($producerTopic, 'conf');
        KafkaIntegration::addProducerSpanMetadata($span, $conf, $hook->args);

        // Inject distributed tracing headers
        $headers = \DDTrace\generate_distributed_tracing_headers();
        $nArgs = count($hook->args);
        if ($nArgs >= 5) { // Add to passed headers
            $hook->args[4] = array_merge($hook->args[4] ?? [], $headers);
        } elseif ($nArgs == 4) { // Add the headers to the args
            $hook->args[] = $headers;
        } else { // Add the message key and headers to the args
            $hook->args[] = null; // $key
            $hook->args[] = $headers; // $headers
        }
        $hook->overrideArguments($hook->args);
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

    private function installConsumerHooks()
    {
        $integration = $this;

        $consumerMethods = [
            'RdKafka\KafkaConsumer::consume',
            'RdKafka\Queue::consume'
        ];

        foreach ($consumerMethods as $method) {
            \DDTrace\install_hook(
                $method,
                function (HookData $hook) use ($integration) {
                    $integration->saveStartTime($hook);
                },
                function (HookData $hook) use ($integration) {
                    $integration->processConsumedMessage($hook);
                    $integration->setupKafkaConsumeSpan($hook, $this);
                    \DDTrace\close_span();
                }
            );
        }
    }

    public function saveStartTime(HookData $hook)
    {
        $hook->data['start'] = \DDTrace\now();
    }

    public function setupKafkaConsumeSpan(HookData $hook, $consumer)
    {
        $span = $hook->data['span'];
        KafkaIntegration::setupCommonSpanMetadata($span, Tag::KAFKA_CONSUME, Tag::SPAN_KIND_VALUE_CONSUMER, Tag::MQ_OPERATION_RECEIVE);

        $conf = ObjectKVStore::get($consumer, 'conf');
        KafkaIntegration::addMetadataToSpan($span, $conf);
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

    private static function addMetadataToSpan($span, $conf)
    {
        foreach (self::METADATA_MAPPING as $configKey => $tagKey) {
            if (isset($conf[$configKey])) {
                $span->meta[$tagKey] = $conf[$configKey];
            }
        }
    }

    public function processConsumedMessage(HookData $hook)
    {
        /** @var \RdKafka\Message $message */
        $message = $hook->returned;

        if ($message) {
            $headers = array_filter(KafkaIntegration::extractMessageHeaders($message->headers ?? []), function($value) {
                return $value !== null;
            });

            if (!empty($headers)) {
                if (\dd_trace_env_config('DD_TRACE_KAFKA_DISTRIBUTED_TRACING')) {
                    $span = \DDTrace\start_trace_span(...$hook->data['start']);
                    \DDTrace\consume_distributed_tracing_headers($headers);
                } else {
                    $span = \DDTrace\start_span(...$hook->data['start']);
                    $span->links[] = SpanLink::fromHeaders($headers);
                }
            } else {
                $span = \DDTrace\start_span(...$hook->data['start']);
            }

            $span->meta[Tag::MQ_DESTINATION] = $message->topic_name;
            $span->meta[Tag::MQ_DESTINATION_KIND] = Type::QUEUE;
            $span->metrics[Tag::KAFKA_PARTITION] = $message->partition;
            $span->metrics[Tag::KAFKA_MESSAGE_OFFSET] = $message->offset;
            $span->metrics[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->payload);
        } else {
            $span = \DDTrace\start_span(...$hook->data['start']);
        }

        if (!$message || $message->payload === null || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            $span->meta[Tag::KAFKA_TOMBSTONE] = true;
        }

        $hook->data['span'] = $span;
    }

    public static function extractMessageHeaders(array $messageHeaders): array
    {
        $tracingHeaders = [
            'x-datadog-sampling-priority' => null,
            'x-datadog-tags' => null,
            'x-datadog-trace-id' => null,
            'x-datadog-parent-id' => null,
            'traceparent' => null,
            'tracestate' => null
        ];

        return array_reduce(
            array_keys($tracingHeaders),
            function($carry, $header) use ($messageHeaders) {
                return array_merge($carry, [$header => $messageHeaders[$header] ?? null]);
            },
            []
        );
    }

    private function installConfigurationHooks()
    {
        $configurationHooks = [
            'RdKafka\KafkaConsumer' => ['__construct'],
            'RdKafka\Producer' => ['__construct', 'newTopic'],
            'RdKafka\Consumer' => ['__construct', 'newQueue']
        ];

        foreach ($configurationHooks as $class => $methods) {
            foreach ($methods as $method) {
                $this->installConfigurationHook($class, $method);
            }
        }
    }

    private function installConfigurationHook(string $class, string $method)
    {
        \DDTrace\hook_method(
            $class,
            $method,
            function ($This, $scope, $args) use ($method) {
                if ($method === '__construct') {
                    $conf = $args[0];
                    ObjectKVStore::put($This, 'conf', $conf->dump());
                }
            },
            function ($This, $scope, $args, $returnValue) use ($method) {
                if (in_array($method, ['newTopic', 'newQueue'])) {
                    $conf = ObjectKVStore::get($This, 'conf');
                    ObjectKVStore::put($returnValue, 'conf', $conf);
                }
            }
        );
    }
}
