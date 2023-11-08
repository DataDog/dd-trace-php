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
                return strtolower($meta['db.system']) . '.query';
            case isset($meta['messaging.system'], $meta['messaging.operation'])
                && (in_array(
                    $spanKind, [
                        Tag::SPAN_KIND_VALUE_CONSUMER,
                        Tag::SPAN_KIND_VALUE_PRODUCER,
                        Tag::SPAN_KIND_VALUE_SERVER,
                        Tag::SPAN_KIND_VALUE_CLIENT
                    ]
                )):
                return strtolower($meta['messaging.system']) . '.' . strtolower($meta['messaging.operation']);
            case isset($meta['rpc.system']) && $meta['rpc.system'] === 'aws-api'
                && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // AWS Client
                return isset($meta['rpc.service'])
                    ? 'aws.' . strtolower($meta['rpc.service']) . '.request'
                    : 'aws.client.request';
            case isset($meta['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // RPC Client
                return strtolower($meta['rpc.system']) . '.client.request';
            case isset($meta['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // RPC Server
                return strtolower($meta['rpc.system']) . '.server.request';
            case isset($meta['faas.trigger']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // FaaS Server
                return strtolower($meta['faas.trigger']) . '.invoke';
            case isset($meta['faas.invoked_provider'], $meta['faas.invoked_name'])
                && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // FaaS Client
                return strtolower($meta['faas.invoked_provider']) . '.' . strtolower($meta['faas.invoked_name']) . '.invoke';
            case isset($meta['graphql.operation.type']):
                return 'graphql.server.request';
            case $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // Generic
            case $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                return isset($meta['network.protocol.name'])
                    ? strtolower($meta['network.protocol.name']) . ".$spanKind.request"
                    : "$spanKind.request";
            case !empty($spanKind):
                return $spanKind;
            default: // If all else fails, we still shouldn't use the resource name
                return 'otel_unknown';
        }
    }
}
