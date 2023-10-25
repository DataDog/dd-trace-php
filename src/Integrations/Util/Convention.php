<?php

namespace DDTrace\Util;

use DDTrace\SpanData;
use DDTrace\Tag;

// Operation Name Conventions
enum Convention
{
    case HTTP_SERVER;
    case HTTP_CLIENT;
    case DATABASE;
    case GRAPHQL_SERVER;
    case RPC_SERVER;
    case RPC_CLIENT;
    case AWS_CLIENT;
    case MESSAGE_PRODUCER;
    case MESSAGE_CONSUMER;
    case FAAS_SERVER;
    case FAAS_CLIENT;
    case GENERIC_SERVER;
    case GENERIC_CLIENT;
    case GENERIC_INTERNAL;
    case GENERIC_PRODUCER;
    case GENERIC_CONSUMER;
    case OT_UNKNOWN;

    public function defaultOperationName(SpanData $span): string
    {
        // Note: Assumes metadata is set
        $meta = $span->meta;
        return match($this) {
            self::HTTP_SERVER => 'http.server.request',
            self::HTTP_CLIENT => 'http.client.request',
            self::DATABASE => $meta['db.system'] . '.query',
            self::GRAPHQL_SERVER => 'graphql.server.request',
            self::RPC_SERVER => $meta['rpc.system'] . '.server.request',
            self::RPC_CLIENT => $meta['rpc.system'] . '.client.request',
            self::AWS_CLIENT => isset($meta['rpc.service'])
                ? 'aws.' . strtolower($meta['rpc.service']) . '.request'
                : 'aws.request', // fallback
            self::MESSAGE_PRODUCER, self::MESSAGE_CONSUMER => strtolower($meta['messaging.system']) . '.' . $meta['messaging.operation'],
            self::FAAS_SERVER => $meta['faas.trigger'] . '.invoke',
            self::FAAS_CLIENT => $meta['faas.invoked_provider'] . '.' . $meta['faas.invoked_name'] . '.invoke',
            self::GENERIC_SERVER => isset($meta['network.protocol.name'])
                ? $meta['network.protocol.name'] . '.server.request'
                : 'server.request', // fallback
            self::GENERIC_CLIENT => isset($meta['network.protocol.name'])
                ? $meta['network.protocol.name'] . '.client.request'
                : 'client.request', // fallback
            self::GENERIC_INTERNAL => isset($meta['network.protocol.name'])
                ? $meta['network.protocol.name'] . '.internal.request'
                : 'internal.request', // fallback
            self::GENERIC_PRODUCER => isset($meta['network.protocol.name'])
                ? $meta['network.protocol.name'] . '.producer.request'
                : 'producer.request', // fallback
            self::GENERIC_CONSUMER => isset($meta['network.protocol.name'])
                ? $meta['network.protocol.name'] . '.consumer.request'
                : 'consumer.request', // fallback
            self::OT_UNKNOWN => 'otel_unknown',
            default => 'otel_unknown',
        };
    }

    public static function conventionOf(SpanData $span): Convention
    {
        $meta = $span->meta;
        if (!isset($meta[Tag::SPAN_KIND])) {
            return match(true) {
                isset($meta['graphql.operation.type']) => self::GRAPHQL_SERVER,
                default => self::OT_UNKNOWN,
            };
        }

        return match(true) {
            isset($meta['http.request.method']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_SERVER => self::HTTP_SERVER,
            isset($meta['http.request.method']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CLIENT => self::HTTP_CLIENT,
            isset($meta['db.system']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CLIENT => self::DATABASE,
            isset($meta['messaging.system'], $meta['messaging.operation']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CLIENT => self::MESSAGE_CONSUMER,
            isset($meta['messaging.system'], $meta['messaging.operation']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CONSUMER => self::MESSAGE_CONSUMER,
            isset($meta['messaging.system'], $meta['messaging.operation']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_PRODUCER => self::MESSAGE_PRODUCER,
            isset($meta['rpc.system']) && $meta['rpc.system'] === 'aws-api' && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CLIENT => self::AWS_CLIENT,
            isset($meta['rpc.system']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CLIENT => self::RPC_CLIENT,
            isset($meta['rpc.system']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_SERVER => self::RPC_SERVER,
            isset($meta['faas.trigger']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_SERVER => self::FAAS_SERVER,
            isset($meta['faas.invoked_provider']) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CLIENT => self::FAAS_CLIENT,
            isset($meta['graphql.operation.type']) => self::GRAPHQL_SERVER,
            isset($meta[Tag::SPAN_KIND]) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_SERVER => self::GENERIC_SERVER,
            isset($meta[Tag::SPAN_KIND]) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CLIENT => self::GENERIC_CLIENT,
            isset($meta[Tag::SPAN_KIND]) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_INTERNAL => self::GENERIC_INTERNAL,
            isset($meta[Tag::SPAN_KIND]) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_PRODUCER => self::GENERIC_PRODUCER,
            isset($meta[Tag::SPAN_KIND]) && $meta[Tag::SPAN_KIND] === Tag::SPAN_KIND_VALUE_CONSUMER => self::GENERIC_CONSUMER,
            default => self::OT_UNKNOWN,
        };
    }
}
