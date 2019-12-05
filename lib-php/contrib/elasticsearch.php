<?php

namespace DDTrace\Contrib\ElasticSearch;

use DDTrace\SpanData;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../tags.php';
require_once __DIR__ . '/../types.php';

const NAME = 'elasticsearch';

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

function _dd_trace_client_method($name, $isTraceAnalyticsCandidate = false)
{
    \dd_trace_method(
        'Elasticsearch\Client',
        $name,
        function (SpanData $span, $args) use ($name, $isTraceAnalyticsCandidate) {
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }

            $span->name = "Elasticsearch.Client.$name";

            if (\dd_config_is_trace_analytics_enabled(NAME)) {
                $span->metrics[\DD_TAG_ANALYTICS_KEY] = \dd_config_trace_analytics_sample_rate(NAME);
            }

            $span->service = 'elasticsearch';
            $span->type = 'elasticsearch';
            $span->resource = build_resource_name($name, isset($args[0]) ? $args[0] : []);
        }
    );
}

function _dd_trace_simple_method($class, $name)
{
    \dd_trace_method($class, $name, function (SpanData $span) use ($class, $name) {
        if (\dd_trace_tracer_is_limited()) {
            return false;
        }
        $operationName = str_replace('\\', '.', "$class.$name");
        $span->name = $operationName;
        $span->resource = $operationName;
        $span->service = NAME;
        $span->type = \DD_TYPE_ELASTICSEARCH;
    });
}

function _dd_trace_namespaced_method($namespace, $name)
{
    \dd_trace_method(
        'Elasticsearch\Namespaces\\' . $namespace,
        $name,
        function (SpanData $span, $args) use ($namespace, $name) {
            if (\dd_trace_tracer_is_limited()) {
                return false;
            }
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$name";
            $span->resource = build_resource_name($name, $params);
            $span->service = NAME;
            $span->type = \DD_TYPE_ELASTICSEARCH;
        }
    );
}

// Client operations
_dd_trace_client_method('__construct');
_dd_trace_client_method('count');
_dd_trace_client_method('delete');
_dd_trace_client_method('exists');
_dd_trace_client_method('explain');
_dd_trace_client_method('get', true);
_dd_trace_client_method('index');
_dd_trace_client_method('scroll');
_dd_trace_client_method('search', true);
_dd_trace_client_method('update');

// Serializers
_dd_trace_simple_method('Elasticsearch\Serializers\ArrayToJSONSerializer', 'serialize');
_dd_trace_simple_method('Elasticsearch\Serializers\ArrayToJSONSerializer', 'deserialize');
_dd_trace_simple_method('Elasticsearch\Serializers\EverythingToJSONSerializer', 'serialize');
_dd_trace_simple_method('Elasticsearch\Serializers\EverythingToJSONSerializer', 'deserialize');
_dd_trace_simple_method('Elasticsearch\Serializers\SmartSerializer', 'serialize');
_dd_trace_simple_method('Elasticsearch\Serializers\SmartSerializer', 'deserialize');

// IndicesNamespace operations
_dd_trace_namespaced_method('IndicesNamespace', 'analyze');
_dd_trace_namespaced_method('IndicesNamespace', 'clearCache');
_dd_trace_namespaced_method('IndicesNamespace', 'close');
_dd_trace_namespaced_method('IndicesNamespace', 'create');
_dd_trace_namespaced_method('IndicesNamespace', 'delete');
_dd_trace_namespaced_method('IndicesNamespace', 'deleteAlias');
_dd_trace_namespaced_method('IndicesNamespace', 'deleteMapping');
_dd_trace_namespaced_method('IndicesNamespace', 'deleteTemplate');
_dd_trace_namespaced_method('IndicesNamespace', 'deleteWarmer');
_dd_trace_namespaced_method('IndicesNamespace', 'exists');
_dd_trace_namespaced_method('IndicesNamespace', 'existsAlias');
_dd_trace_namespaced_method('IndicesNamespace', 'existsTemplate');
_dd_trace_namespaced_method('IndicesNamespace', 'existsType');
_dd_trace_namespaced_method('IndicesNamespace', 'flush');
_dd_trace_namespaced_method('IndicesNamespace', 'getAlias');
_dd_trace_namespaced_method('IndicesNamespace', 'getAliases');
_dd_trace_namespaced_method('IndicesNamespace', 'getFieldMapping');
_dd_trace_namespaced_method('IndicesNamespace', 'getMapping');
_dd_trace_namespaced_method('IndicesNamespace', 'getSettings');
_dd_trace_namespaced_method('IndicesNamespace', 'getTemplate');
_dd_trace_namespaced_method('IndicesNamespace', 'getWarmer');
_dd_trace_namespaced_method('IndicesNamespace', 'open');
_dd_trace_namespaced_method('IndicesNamespace', 'optimize');
_dd_trace_namespaced_method('IndicesNamespace', 'putAlias');
_dd_trace_namespaced_method('IndicesNamespace', 'putMapping');
_dd_trace_namespaced_method('IndicesNamespace', 'putSettings');
_dd_trace_namespaced_method('IndicesNamespace', 'putTemplate');
_dd_trace_namespaced_method('IndicesNamespace', 'putWarmer');
_dd_trace_namespaced_method('IndicesNamespace', 'recovery');
_dd_trace_namespaced_method('IndicesNamespace', 'refresh');
_dd_trace_namespaced_method('IndicesNamespace', 'segments');
_dd_trace_namespaced_method('IndicesNamespace', 'snapshotIndex');
_dd_trace_namespaced_method('IndicesNamespace', 'stats');
_dd_trace_namespaced_method('IndicesNamespace', 'status');
_dd_trace_namespaced_method('IndicesNamespace', 'updateAliases');
_dd_trace_namespaced_method('IndicesNamespace', 'validateQuery');

// CatNamespace operations
_dd_trace_namespaced_method('CatNamespace', 'aliases');
_dd_trace_namespaced_method('CatNamespace', 'allocation');
_dd_trace_namespaced_method('CatNamespace', 'count');
_dd_trace_namespaced_method('CatNamespace', 'fielddata');
_dd_trace_namespaced_method('CatNamespace', 'health');
_dd_trace_namespaced_method('CatNamespace', 'help');
_dd_trace_namespaced_method('CatNamespace', 'indices');
_dd_trace_namespaced_method('CatNamespace', 'master');
_dd_trace_namespaced_method('CatNamespace', 'nodes');
_dd_trace_namespaced_method('CatNamespace', 'pendingTasks');
_dd_trace_namespaced_method('CatNamespace', 'recovery');
_dd_trace_namespaced_method('CatNamespace', 'shards');
_dd_trace_namespaced_method('CatNamespace', 'threadPool');

// SnapshotNamespace operations
_dd_trace_namespaced_method('SnapshotNamespace', 'create');
_dd_trace_namespaced_method('SnapshotNamespace', 'createRepository');
_dd_trace_namespaced_method('SnapshotNamespace', 'delete');
_dd_trace_namespaced_method('SnapshotNamespace', 'deleteRepository');
_dd_trace_namespaced_method('SnapshotNamespace', 'get');
_dd_trace_namespaced_method('SnapshotNamespace', 'getRepository');
_dd_trace_namespaced_method('SnapshotNamespace', 'restore');
_dd_trace_namespaced_method('SnapshotNamespace', 'status');

// ClusterNamespace operations
_dd_trace_namespaced_method('ClusterNamespace', 'getSettings');
_dd_trace_namespaced_method('ClusterNamespace', 'health');
_dd_trace_namespaced_method('ClusterNamespace', 'pendingTasks');
_dd_trace_namespaced_method('ClusterNamespace', 'putSettings');
_dd_trace_namespaced_method('ClusterNamespace', 'reroute');
_dd_trace_namespaced_method('ClusterNamespace', 'state');
_dd_trace_namespaced_method('ClusterNamespace', 'stats');

// NodesNamespace operations
_dd_trace_namespaced_method('NodesNamespace', 'hotThreads');
_dd_trace_namespaced_method('NodesNamespace', 'info');
_dd_trace_namespaced_method('NodesNamespace', 'shutdown');
_dd_trace_namespaced_method('NodesNamespace', 'stats');
