<?php

namespace DDTrace\Util;

use DDTrace\SpanData;
use DDTrace\Tag;

// Operation Name Conventions
class Convention
{
    public static function defaultOperationName(SpanData $span): string
    {
        $meta = $span->meta;
        $spanKind = $meta[Tag::SPAN_KIND] ?? null;

        switch (true) {
            case isset($meta['http.request.method']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // HTTP Server
                return 'http.server.request';
            case isset($meta['http.request.method']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // HTTP Client
                return 'http.client.request';
            case isset($meta['db.system']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // Database
                return ($meta['db.system'] ?? 'otel_unknown') . '.query';
            case isset($meta['messaging.system'], $meta['messaging.operation']) && $spanKind === Tag::SPAN_KIND_VALUE_CONSUMER: // Message Consumer
            case isset($meta['messaging.system'], $meta['messaging.operation']) && $spanKind === Tag::SPAN_KIND_VALUE_PRODUCER: // Message Producer
            case isset($meta['messaging.system'], $meta['messaging.operation']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // Message Consumer
                return (strtolower($meta['messaging.system'] ?? 'otel_unknown')) . '.' . ($meta['messaging.operation'] ?? 'otel_unknown');
            case isset($meta['rpc.system']) && $meta['rpc.system'] === 'aws-api' && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // AWS Client
                return isset($meta['rpc.service'])
                    ? 'aws.' . strtolower($meta['rpc.service']) . '.request'
                    : 'aws.request';
            case isset($meta['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // RPC Client
                return ($meta['rpc.system'] ?? 'otel_unknown') . '.client.request';
            case isset($meta['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // RPC Server
                return ($meta['rpc.system'] ?? 'otel_unknown') . '.server.request';
            case isset($meta['faas.trigger']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // FaaS Server
                return ($meta['faas.trigger'] ?? 'otel_unknown') . '.invoke';
            case isset($meta['faas.invoked_provider']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // FaaS Client
                return ($meta['faas.invoked_provider'] ?? 'otel_unknown') . '.' . ($meta['faas.invoked_name'] ?? 'otel_unknown') . '.invoke';
            case isset($meta['graphql.operation.type']):
                return 'graphql.server.request';
            case !empty($spanKind): // Generic Span
                return isset($meta['network.protocol.name']) ? "{$meta['network.protocol.name']}.{$spanKind}.request" : "{$spanKind}.request";
            default: // If all else fails, we still shouldn't use the resource name
                return 'otel_unknown';
        }
    }
}
