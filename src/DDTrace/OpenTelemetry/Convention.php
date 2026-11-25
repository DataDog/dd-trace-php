<?php

namespace DDTrace\OpenTelemetry;

use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Util\SpanKindNames;

// Operation Name Conventions
class Convention
{
    public static function defaultOperationName(SpanData $span): string
    {
        $attributes = $span->attributes;
        // A non-canonical span.kind stays a plain attribute
        $spanKind = SpanKindNames::NAMES[$span->spanKind] ?? $attributes[Tag::SPAN_KIND] ?? null;

        switch (true) {
            case isset($attributes['http.request.method']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // HTTP Server
                return 'http.server.request';
            case isset($attributes['http.request.method']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // HTTP Client
                return 'http.client.request';
            case isset($attributes['db.system']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // Database
                return strtolower($attributes['db.system']) . '.query';
            case isset($attributes['messaging.system'], $attributes['messaging.operation'])
                && (in_array(
                    $spanKind, [
                        Tag::SPAN_KIND_VALUE_CONSUMER,
                        Tag::SPAN_KIND_VALUE_PRODUCER,
                        Tag::SPAN_KIND_VALUE_SERVER,
                        Tag::SPAN_KIND_VALUE_CLIENT
                    ]
                )):
                return strtolower($attributes['messaging.system']) . '.' . strtolower($attributes['messaging.operation']);
            case isset($attributes['rpc.system']) && $attributes['rpc.system'] === 'aws-api'
                && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // AWS Client
                return isset($attributes['rpc.service'])
                    ? 'aws.' . strtolower($attributes['rpc.service']) . '.request'
                    : 'aws.client.request';
            case isset($attributes['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // RPC Client
                return strtolower($attributes['rpc.system']) . '.client.request';
            case isset($attributes['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // RPC Server
                return strtolower($attributes['rpc.system']) . '.server.request';
            case isset($attributes['faas.trigger']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // FaaS Server
                return strtolower($attributes['faas.trigger']) . '.invoke';
            case isset($attributes['faas.invoked_provider'], $attributes['faas.invoked_name'])
                && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT: // FaaS Client
                return strtolower($attributes['faas.invoked_provider']) . '.' . strtolower($attributes['faas.invoked_name']) . '.invoke';
            case isset($attributes['graphql.operation.type']):
                return 'graphql.server.request';
            case $spanKind === Tag::SPAN_KIND_VALUE_SERVER: // Generic
            case $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                return isset($attributes['network.protocol.name'])
                    ? strtolower($attributes['network.protocol.name']) . ".$spanKind.request"
                    : "$spanKind.request";
            case !empty($spanKind):
                return $spanKind;
            default: // If all else fails, we still shouldn't use the resource name
                return '';
        }
    }
}
