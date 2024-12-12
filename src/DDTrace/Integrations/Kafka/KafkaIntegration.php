<?php

namespace DDTrace\Integrations\Kafka;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class KafkaIntegration extends Integration
{
    const NAME = 'kafka';

    public function init(): int
    {
        $this->installProducerTopicHooks();
        $this->installConsumerHooks();
        $this->installConfigurationHooks();

        return Integration::LOADED;
    }

    private function installProducerTopicHooks(): void
    {
        $integration = $this;

        $hooks = [
            'RdKafka\ProducerTopic::produce',
            'RdKafka\ProducerTopic::producev'
        ];

        foreach ($hooks as $hookMethod) {
            \DDTrace\install_hook(
                $hookMethod,
                function (HookData $hook) use ($integration) {
                    $integration->setupKafkaProduceSpan($hook);
                }
            );
        }
    }

    public function setupKafkaProduceSpan(HookData $hook): void
    {
        /** @var \RdKafka\ProducerTopic $this */
        $span = $hook->span();
        KafkaIntegration::setupCommonSpanMetadata($span, Tag::KAFKA_PRODUCE, Tag::SPAN_KIND_VALUE_PRODUCER, Tag::MQ_OPERATION_SEND);

        $span->meta[Tag::MQ_DESTINATION] = $this->getName();
        $span->meta[Tag::MQ_DESTINATION_KIND] = Type::QUEUE;

        $conf = ObjectKVStore::get($this, 'conf');
        KafkaIntegration::addProducerSpanMetadata($span, $conf, $hook->args);
    }

    public static function addProducerSpanMetadata($span, $conf, $args): void
    {
        $metadata = [
            'metadata.broker.list' => Tag::KAFKA_HOST_LIST,
            'group.id' => Tag::KAFKA_GROUP_ID,
            'client.id' => Tag::KAFKA_CLIENT_ID
        ];

        foreach ($metadata as $configKey => $tagKey) {
            if (isset($conf[$configKey])) {
                $span->meta[$tagKey] = $conf[$configKey];
            }
        }

        $span->metrics[Tag::KAFKA_PARTITION] = $args[0];
        $span->metrics[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($args[2]);

        if (isset($args[3])) {
            $span->meta[Tag::KAFKA_MESSAGE_KEY] = $args[3];
        }
    }

    private function installConsumerHooks(): void
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
                    $integration->setupKafkaConsumeSpan($hook);
                },
                function (HookData $hook) use ($integration) {
                    $integration->processConsumedMessage($hook);
                }
            );
        }
    }

    public function setupKafkaConsumeSpan(HookData $hook): void
    {
        $span = $hook->span();
        KafkaIntegration::setupCommonSpanMetadata($span, Tag::KAFKA_CONSUME, Tag::SPAN_KIND_VALUE_CONSUMER, Tag::MQ_OPERATION_RECEIVE);

        $conf = ObjectKVStore::get($this, 'conf');
        KafkaIntegration::addConsumerSpanMetadata($span, $conf);
    }

    public static function setupCommonSpanMetadata($span, string $name, string $spanKind, string $operation): void
    {
        $span->name = $name;
        $span->type = Type::QUEUE;
        $span->meta[Tag::SPAN_KIND] = $spanKind;
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::MQ_SYSTEM] = self::NAME;
        $span->meta[Tag::MQ_OPERATION] = $operation;
    }

    public static function addConsumerSpanMetadata($span, $conf): void
    {
        $metadata = [
            'metadata.broker.list' => Tag::KAFKA_HOST_LIST,
            'client.id' => Tag::KAFKA_CLIENT_ID,
            'group.id' => Tag::KAFKA_GROUP_ID
        ];

        foreach ($metadata as $configKey => $tagKey) {
            if (isset($conf[$configKey])) {
                $span->meta[$tagKey] = $conf[$configKey];
            }
        }
    }

    public function processConsumedMessage(HookData $hook): void
    {
        /** @var \RdKafka\Message $message */
        $message = $hook->returned;
        $span = $hook->span();

        if ($message) {
            $span->meta[Tag::MQ_DESTINATION] = $message->topic_name;
            $span->metrics[Tag::KAFKA_PARTITION] = $message->partition;
            $span->metrics[Tag::KAFKA_MESSAGE_OFFSET] = $message->offset;
        }

        if (!$message || $message->payload === null || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            $span->meta[Tag::KAFKA_TOMBSTONE] = true;
            return;
        }

        $headers = KafkaIntegration::extractMessageHeaders($message->headers ?? []);
        \DDTrace\consume_distributed_tracing_headers($headers);
        $span->metrics[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->payload);
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

        return array_map(
            fn($header) => $messageHeaders[$header] ?? null,
            array_keys($tracingHeaders)
        );
    }

    private function installConfigurationHooks(): void
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

    private function installConfigurationHook(string $class, string $method): void
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
