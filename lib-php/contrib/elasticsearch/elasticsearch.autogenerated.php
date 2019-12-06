<?php

use DDTrace\SpanData;

// This file is autogenerate from /Users/luca.abbati/projects/datadog/dd-trace-php/lib-php/contrib/elasticsearch/elasticsearch.template.php

function build_resource_name($methodName, $params)
{
    if (!is_array($params)) {
        return $methodName;
    }

    $resourceFragments = [$methodName];
    $relevantParamNames = ['index', 'type'];

    foreach ($relevantParamNames as $relevantParamName) {
        if (empty($params[$relevantParamName])) {
            continue;
        }
        $resourceFragments[] = $relevantParamName . ':' . $params[$relevantParamName];
    }

    return implode(' ', $resourceFragments);
}


\dd_trace_method(
        'Elasticsearch\Client',
        '__construct',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = '__construct';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'count',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'count';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'delete',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'delete';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'exists',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'exists';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'explain',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'explain';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'get',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'get';
            $isTraceAnalyticsCandidate = true;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'index',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'index';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'scroll',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'scroll';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'search',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'search';
            $isTraceAnalyticsCandidate = true;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method(
        'Elasticsearch\Client',
        'update',
        function (SpanData $span, $args) {
            $integration = 'elasticsearch';
            $name = 'update';
            $isTraceAnalyticsCandidate = false;
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if ($isTraceAnalyticsCandidate && \dd_config_is_trace_analytics_enabled($integration)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate($integration);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );

\dd_trace_method('Elasticsearch\Serializers\ArrayToJSONSerializer', 'serialize', function (SpanData $span) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $type = '[param::type]';
        $class = 'Elasticsearch\Serializers\ArrayToJSONSerializer';
        $method = 'serialize';
        $integration = 'elasticsearch';
        $operationName = str_replace('\\', '.', "$class.$method");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = $integration;
        $span->type = $type;
    });

\dd_trace_method('Elasticsearch\Serializers\ArrayToJSONSerializer', 'deserialize', function (SpanData $span) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $type = '[param::type]';
        $class = 'Elasticsearch\Serializers\ArrayToJSONSerializer';
        $method = 'deserialize';
        $integration = 'elasticsearch';
        $operationName = str_replace('\\', '.', "$class.$method");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = $integration;
        $span->type = $type;
    });

\dd_trace_method('Elasticsearch\Serializers\EverythingToJSONSerializer', 'serialize', function (SpanData $span) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $type = '[param::type]';
        $class = 'Elasticsearch\Serializers\EverythingToJSONSerializer';
        $method = 'serialize';
        $integration = 'elasticsearch';
        $operationName = str_replace('\\', '.', "$class.$method");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = $integration;
        $span->type = $type;
    });

\dd_trace_method('Elasticsearch\Serializers\EverythingToJSONSerializer', 'deserialize', function (SpanData $span) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $type = '[param::type]';
        $class = 'Elasticsearch\Serializers\EverythingToJSONSerializer';
        $method = 'deserialize';
        $integration = 'elasticsearch';
        $operationName = str_replace('\\', '.', "$class.$method");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = $integration;
        $span->type = $type;
    });

\dd_trace_method('Elasticsearch\Serializers\SmartSerializer', 'serialize', function (SpanData $span) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $type = '[param::type]';
        $class = 'Elasticsearch\Serializers\SmartSerializer';
        $method = 'serialize';
        $integration = 'elasticsearch';
        $operationName = str_replace('\\', '.', "$class.$method");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = $integration;
        $span->type = $type;
    });

\dd_trace_method('Elasticsearch\Serializers\SmartSerializer', 'deserialize', function (SpanData $span) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $type = '[param::type]';
        $class = 'Elasticsearch\Serializers\SmartSerializer';
        $method = 'deserialize';
        $integration = 'elasticsearch';
        $operationName = str_replace('\\', '.', "$class.$method");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = $integration;
        $span->type = $type;
    });

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'analyze',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'analyze';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'clearCache',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'clearCache';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'close',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'close';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'create',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'create';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'delete',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'delete';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'deleteAlias',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'deleteAlias';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'deleteMapping',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'deleteMapping';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'deleteTemplate',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'deleteTemplate';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'deleteWarmer',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'deleteWarmer';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'exists',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'exists';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'existsAlias',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'existsAlias';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'existsTemplate',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'existsTemplate';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'existsType',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'existsType';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'flush',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'flush';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'getAlias',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'getAlias';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'getAliases',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'getAliases';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'getFieldMapping',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'getFieldMapping';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'getMapping',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'getMapping';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'getSettings',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'getSettings';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'getTemplate',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'getTemplate';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'getWarmer',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'getWarmer';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'open',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'open';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'optimize',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'optimize';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'putAlias',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'putAlias';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'putMapping',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'putMapping';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'putSettings',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'putSettings';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'putTemplate',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'putTemplate';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'putWarmer',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'putWarmer';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'recovery',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'recovery';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'refresh',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'refresh';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'segments',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'segments';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'snapshotIndex',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'snapshotIndex';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'stats',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'stats';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'status',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'status';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'updateAliases',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'updateAliases';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'IndicesNamespace',
        'validateQuery',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'IndicesNamespace';
            $method = 'validateQuery';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'aliases',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'aliases';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'allocation',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'allocation';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'count',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'count';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'fielddata',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'fielddata';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'health',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'health';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'help',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'help';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'indices',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'indices';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'master',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'master';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'nodes',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'nodes';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'pendingTasks',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'pendingTasks';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'recovery',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'recovery';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'shards',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'shards';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'CatNamespace',
        'threadPool',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'CatNamespace';
            $method = 'threadPool';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'create',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'create';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'createRepository',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'createRepository';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'delete',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'delete';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'deleteRepository',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'deleteRepository';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'get',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'get';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'getRepository',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'getRepository';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'restore',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'restore';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'SnapshotNamespace',
        'status',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'SnapshotNamespace';
            $method = 'status';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'ClusterNamespace',
        'getSettings',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'ClusterNamespace';
            $method = 'getSettings';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'ClusterNamespace',
        'health',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'ClusterNamespace';
            $method = 'health';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'ClusterNamespace',
        'pendingTasks',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'ClusterNamespace';
            $method = 'pendingTasks';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'ClusterNamespace',
        'putSettings',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'ClusterNamespace';
            $method = 'putSettings';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'ClusterNamespace',
        'reroute',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'ClusterNamespace';
            $method = 'reroute';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'ClusterNamespace',
        'state',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'ClusterNamespace';
            $method = 'state';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'ClusterNamespace',
        'stats',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'ClusterNamespace';
            $method = 'stats';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'NodesNamespace',
        'hotThreads',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'NodesNamespace';
            $method = 'hotThreads';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'NodesNamespace',
        'info',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'NodesNamespace';
            $method = 'info';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'NodesNamespace',
        'shutdown',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'NodesNamespace';
            $method = 'shutdown';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );

\dd_trace_method(
        'Elasticsearch\Namespaces\\' . 'NodesNamespace',
        'stats',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = 'elasticsearch';
            $namespace = 'NodesNamespace';
            $method = 'stats';
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$method";
            $span->resource = build_resource_name($method, $params);
            $span->service = $integration;
            $span->type = $type;
        }
    );
