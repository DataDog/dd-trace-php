<?php

require_once __DIR__ . '/../common.php';

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

function _dd_trace_client_method()
{
    \dd_trace_method(
        'Elasticsearch\Client',
        '[param::method]',
        function (SpanData $span, $args) {
            $integration = '[param::integration]';
            $name = '[param::method]';
            $isTraceAnalyticsCandidate = '[param::isTraceAnalyticsCandidate]';
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
}

function _dd_trace_simple_method()
{
    \dd_trace_method('[param::class]', '[param::method]', function (SpanData $span) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $type = '[param::type]';
        $class = '[param::class]';
        $method = '[param::method]';
        $integration = '[param::integration]';
        $operationName = str_replace('\\', '.', "$class.$method");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = $integration;
        $span->type = $type;
    });
}

function _dd_trace_namespaced_method()
{
    \dd_trace_method(
        'Elasticsearch\Namespaces\\' . '[param::namespace]',
        '[param::method]',
        function (SpanData $span, $args){
            $type = '[param::type]';
            $integration = '[param::integration]';
            $namespace = '[param::namespace]';
            $method = '[param::method]';
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
}

$integration = 'elasticsearch';

start_file($integration);

export_raw_function($integration, 'build_resource_name');

// Client operations
$defaultParams = [
    'isTraceAnalyticsCandidate' => false,
    'integration' => $integration,
];
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => '__construct']);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'count']);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'delete']);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'exists']);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'explain']);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'get', 'isTraceAnalyticsCandidate' => true]);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'index']);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'scroll']);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'search', 'isTraceAnalyticsCandidate' => true]);
export_tracing_function($integration, '_dd_trace_client_method', $defaultParams, ['method' => 'update']);

// Serializers
$defaultParams = [
    'integration' => $integration,
];
export_tracing_function(
    $integration,
    '_dd_trace_simple_method',
    $defaultParams,
    [
        'class' => 'Elasticsearch\Serializers\ArrayToJSONSerializer',
        'method' => 'serialize',
    ]
);
export_tracing_function(
    $integration,
    '_dd_trace_simple_method',
    $defaultParams,
    [
        'class' => 'Elasticsearch\Serializers\ArrayToJSONSerializer',
        'method' => 'deserialize',
    ]
);
export_tracing_function(
    $integration,
    '_dd_trace_simple_method',
    $defaultParams,
    [
        'class' => 'Elasticsearch\Serializers\EverythingToJSONSerializer',
        'method' => 'serialize',
    ]
);
export_tracing_function(
    $integration,
    '_dd_trace_simple_method',
    $defaultParams,
    [
        'class' => 'Elasticsearch\Serializers\EverythingToJSONSerializer',
        'method' => 'deserialize',
    ]
);
export_tracing_function(
    $integration,
    '_dd_trace_simple_method',
    $defaultParams,
    [
        'class' => 'Elasticsearch\Serializers\SmartSerializer',
        'method' => 'serialize',
    ]
);
export_tracing_function(
    $integration,
    '_dd_trace_simple_method',
    $defaultParams,
    [
        'class' => 'Elasticsearch\Serializers\SmartSerializer',
        'method' => 'deserialize',
    ]
);

// IndicesNamespace operations
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'analyze' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'clearCache' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'close' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'create' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'delete' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'deleteAlias' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'deleteMapping' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'deleteTemplate' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'deleteWarmer' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'exists' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'existsAlias' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'existsTemplate' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'existsType' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'flush' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'getAlias' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'getAliases' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'getFieldMapping' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'getMapping' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'getSettings' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'getTemplate' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'getWarmer' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'open' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'optimize' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'putAlias' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'putMapping' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'putSettings' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'putTemplate' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'putWarmer' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'recovery' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'refresh' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'segments' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'snapshotIndex' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'stats' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'status' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'updateAliases' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'IndicesNamespace', 'method' => 'validateQuery' ]);

// CatNamespace operations
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'aliases' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'allocation' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'count' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'fielddata' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'health' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'help' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'indices' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'master' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'nodes' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'pendingTasks' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'recovery' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'shards' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'CatNamespace', 'method' => 'threadPool' ]);

// SnapshotNamespace operations
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'create' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'createRepository' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'delete' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'deleteRepository' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'get' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'getRepository' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'restore' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'SnapshotNamespace', 'method' => 'status' ]);

// ClusterNamespace operations
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'ClusterNamespace', 'method' => 'getSettings' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'ClusterNamespace', 'method' => 'health' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'ClusterNamespace', 'method' => 'pendingTasks' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'ClusterNamespace', 'method' => 'putSettings' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'ClusterNamespace', 'method' => 'reroute' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'ClusterNamespace', 'method' => 'state' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'ClusterNamespace', 'method' => 'stats' ]);

// NodesNamespace operations
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'NodesNamespace', 'method' => 'hotThreads' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'NodesNamespace', 'method' => 'info' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'NodesNamespace', 'method' => 'shutdown' ]);
export_tracing_function($integration, '_dd_trace_namespaced_method', $defaultParams, [ 'namespace' => 'NodesNamespace', 'method' => 'stats' ]);
