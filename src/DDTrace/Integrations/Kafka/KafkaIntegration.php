<?php

namespace DDTrace\Integrations\Kafka;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class KafkaIntegration extends Integration
{
    const NAME = 'kafka';

    public function init(): int
    {
        \DDTrace\install_hook(
            'RdKafka\ProducerTopic::produce',
            function (HookData $hook) {
                /** @var \RdKafka\ProducerTopic $this */
                Logger::get()->debug("Hooked RdKafka\ProducerTopic::produce");

                $span = $hook->span();
                $span->name = Tag::KAFKA_PRODUCE;
                $span->type = Type::QUEUE;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_PRODUCER;
                $span->meta[Tag::COMPONENT] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_SYSTEM] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_OPERATION] = Tag::MQ_OPERATION_SEND;

                $args = $hook->args;
                $partition = $args[0];

                $span->meta[Tag::MQ_DESTINATION] = $this->getName();
                $span->meta[Tag::MQ_DESTINATION_KIND] = Type::QUEUE;
                $span->metrics[Tag::KAFKA_PARTITION] = $partition;

                $conf = ObjectKVStore::get($this, 'conf');
                //Logger::get()->debug("ProducerTopic conf");
                //Logger::get()->debug(json_encode($conf, JSON_PRETTY_PRINT));
                $broker = $conf['metadata.broker.list'] ?? null;
                if ($broker) {
                    $span->meta[Tag::KAFKA_HOST_LIST] = $broker;
                }

                $groupId = $conf['group.id'] ?? null;
                if ($groupId) {
                    $span->meta[Tag::KAFKA_GROUP_ID] = $groupId;
                }

                $clientId = $conf['client.id'] ?? null;
                if ($clientId) {
                    $span->meta[Tag::KAFKA_CLIENT_ID] = $clientId;
                }

                if (isset($args[3])) {
                    $span->meta[Tag::KAFKA_MESSAGE_KEY] = $args[3];
                }
            }
        );

        \DDTrace\install_hook(
            'RdKafka\ProducerTopic::producev',
            function (HookData $hook) {
                /** @var \RdKafka\ProducerTopic $this */
                Logger::get()->debug("Hooked RdKafka\Producer::producev");

                $span = $hook->span();
                $span->name = Tag::KAFKA_PRODUCE;
                $span->type = Type::QUEUE;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_PRODUCER;
                $span->meta[Tag::COMPONENT] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_SYSTEM] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_OPERATION] = Tag::MQ_OPERATION_SEND;

                $args = $hook->args;
                $partition = $args[0];

                $span->meta[Tag::MQ_DESTINATION] = $this->getName();
                $span->meta[Tag::MQ_DESTINATION_KIND] = Type::QUEUE;
                $span->metrics[Tag::KAFKA_PARTITION] = $partition;

                $conf = ObjectKVStore::get($this, 'conf');
                //Logger::get()->debug("ProducerTopic conf");
                //Logger::get()->debug(json_encode($conf, JSON_PRETTY_PRINT));
                $broker = $conf['metadata.broker.list'] ?? null;
                if ($broker) {
                    $span->meta[Tag::KAFKA_HOST_LIST] = $broker;
                }

                $groupId = $conf['group.id'] ?? null;
                if ($groupId) {
                    $span->meta[Tag::KAFKA_GROUP_ID] = $groupId;
                }

                $clientId = $conf['client.id'] ?? null;
                if ($clientId) {
                    $span->meta[Tag::KAFKA_CLIENT_ID] = $clientId;
                }

                if (isset($args[3])) {
                    $span->meta[Tag::KAFKA_MESSAGE_KEY] = $args[3];
                }
            }
        );

        \DDTrace\install_hook(
            'RdKafka\KafkaConsumer::consume',
            function (HookData $hook) {
                Logger::get()->debug("Hooked RdKafka\KafkaConsumer::consume");

                $span = $hook->span();
                $span->name = Tag::KAFKA_CONSUME;
                $span->type = Type::QUEUE;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CONSUMER;
                $span->meta[Tag::COMPONENT] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_SYSTEM] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_OPERATION] = Tag::MQ_OPERATION_RECEIVE;

                $conf = ObjectKVStore::get($this, 'conf');
                $broker = $conf['metadata.broker.list'] ?? null;
                if ($broker) {
                    $span->meta[Tag::KAFKA_HOST_LIST] = $broker;
                }

                $clientId = $conf['client.id'] ?? null;
                if ($clientId) {
                    $span->meta[Tag::KAFKA_CLIENT_ID] = $clientId;
                }

                $groupId = $conf['group.id'] ?? null;
                if ($groupId) {
                    $span->meta[Tag::KAFKA_GROUP_ID] = $groupId;
                }
            }, function (HookData $hook) {
                /** @var \RdKafka\Message $args */
                $message = $hook->returned;

                $span = $hook->span();
                if ($message) {
                    $span->meta[Tag::MQ_DESTINATION] = $message->topic_name;
                    $span->metrics[Tag::KAFKA_PARTITION] = $message->partition;
                    $span->metrics[Tag::KAFKA_MESSAGE_OFFSET] = $message->offset;
                }

                // Extract context
                // We are searching for the following headers:
                // x-datadog-sampling-priority, x-datadog-tags, _dd.p.tid, _dd.p.dm, x-datadog-trace-id, x-datadog-parent-id, traceparent, tracestate
                if (!$message || $message->payload === null || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                    $span->meta[Tag::KAFKA_TOMBSTONE] = true;
                } else {
                    $messageHeaders = $message->headers;
                    $headers = [
                        'x-datadog-sampling-priority' => null,
                        'x-datadog-tags' => null,
                        'x-datadog-trace-id' => null,
                        'x-datadog-parent-id' => null,
                        'traceparent' => null,
                        'tracestate' => null
                    ];
                    foreach ($headers as $header => $value) {
                        $headerValue = $messageHeaders[$header] ?? null;
                        if ($headerValue !== null) {
                            $headers[$header] = $headerValue;
                        }
                    }
                    //Logger::get()->debug(json_encode($headers, JSON_PRETTY_PRINT));
                    \DDTrace\consume_distributed_tracing_headers($headers);

                    $span->metrics[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->payload);
                }
            }
        );

        \DDTrace\install_hook(
            'RdKafka\Queue::consume',
            function (HookData $hook) {
                Logger::get()->debug("Hooked RdKafka\Queue::consume");

                $span = $hook->span();
                $span->name = Tag::KAFKA_CONSUME;
                $span->type = Type::QUEUE;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CONSUMER;
                $span->meta[Tag::COMPONENT] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_SYSTEM] = KafkaIntegration::NAME;
                $span->meta[Tag::MQ_OPERATION] = Tag::MQ_OPERATION_RECEIVE;

                $conf = ObjectKVStore::get($this, 'conf');
                $broker = $conf['metadata.broker.list'] ?? null;
                if ($broker) {
                    $span->meta[Tag::KAFKA_HOST_LIST] = $broker;
                }

                $clientId = $conf['client.id'] ?? null;
                if ($clientId) {
                    $span->meta[Tag::KAFKA_CLIENT_ID] = $clientId;
                }

                $groupId = $conf['group.id'] ?? null;
                if ($groupId) {
                    $span->meta[Tag::KAFKA_GROUP_ID] = $groupId;
                }
            }, function (HookData $hook) {
                /** @var \RdKafka\Message $args */
                $message = $hook->returned;

                $span = $hook->span();
                if ($message) {
                    $span->meta[Tag::MQ_DESTINATION] = $message->topic_name;
                    $span->metrics[Tag::KAFKA_PARTITION] = $message->partition;
                    $span->metrics[Tag::KAFKA_MESSAGE_OFFSET] = $message->offset;
                }

                // Extract context
                // We are searching for the following headers:
                // x-datadog-sampling-priority, x-datadog-tags, _dd.p.tid, _dd.p.dm, x-datadog-trace-id, x-datadog-parent-id, traceparent, tracestate
                if (!$message || $message->payload === null || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                    $span->meta[Tag::KAFKA_TOMBSTONE] = true;
                } else {
                    $messageHeaders = $message->headers;
                    $headers = [
                        'x-datadog-sampling-priority' => null,
                        'x-datadog-tags' => null,
                        'x-datadog-trace-id' => null,
                        'x-datadog-parent-id' => null,
                        'traceparent' => null,
                        'tracestate' => null
                    ];
                    foreach ($headers as $header => $value) {
                        $headerValue = $messageHeaders[$header] ?? null;
                        if ($headerValue !== null) {
                            $headers[$header] = $headerValue;
                        }
                    }
                    //Logger::get()->debug(json_encode($headers, JSON_PRETTY_PRINT));
                    \DDTrace\consume_distributed_tracing_headers($headers);

                    $span->metrics[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->payload);
                }
            }
        );

        // ---- Conf ----

        \DDTrace\hook_method(
            'RdKafka\KafkaConsumer',
            '__construct',
            function ($This, $scope, $args) {
                $conf = $args[0];
                ObjectKVStore::put($This, 'conf', $conf->dump());
                Logger::get()->debug("KafkaConsumer conf");
                Logger::get()->debug(json_encode($conf->dump(), JSON_PRETTY_PRINT));
            }
        );

        \DDTrace\hook_method(
            'RdKafka\Producer',
            '__construct',
            function ($This, $scope, $args) {
                $conf = $args[0];
                ObjectKVStore::put($This, 'conf', $conf->dump());
            }
        );

        \DDTrace\hook_method(
            'RdKafka\Producer',
            'newTopic',
            null,
            function ($This, $scope, $args, $returnValue) {
                $conf = ObjectKVStore::get($This, 'conf');
                ObjectKVStore::put($returnValue, 'conf', $conf);
            }
        );

        \DDTrace\hook_method(
            'RdKafka\Consumer',
            '__construct',
            function ($This, $scope, $args) {
                $conf = $args[0];
                ObjectKVStore::put($This, 'conf', $conf->dump());
            }
        );

        \DDTrace\hook_method(
            'RdKafka\Consumer',
            'newQueue',
            null,
            function ($This, $scope, $args, $returnValue) {
                $conf = ObjectKVStore::get($This, 'conf');
                ObjectKVStore::put($returnValue, 'conf', $conf);
            }
        );

        return Integration::LOADED;
    }
}
