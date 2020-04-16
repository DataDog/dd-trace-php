<?php

namespace DDTrace\Integrations\ElasticSearch;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

const NAME = 'elasticsearch';

// This will disappear once we do this check at c-level
$DD_ES_GLOBAL = [
    'loaded' => false,
];

function _dd_build_resource_name($methodName, $params)
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

function _dd_integration_elasticsearch_client($analitycsEnabled, $analyticsSampleRate)
{
    /*
    * The Client `$params` array is mutated by extractArgument().
    * @see https://github.com/elastic/elasticsearch-php/blob/1.x/src/Elasticsearch/Client.php#L1710-L1723
    * Since the arguments passed to the tracing closure on PHP 7 are mutable,
    * the closure must be run _before_ the original call via 'prehook'.
    */
    $hookType = (PHP_MAJOR_VERSION >= 7) ? 'prehook' : 'posthook';
    \dd_trace_method(
        'Elasticsearch\Client',
        '__construct',
        [
            $hookType => function (SpanData $span, $args) use ($hookType, $analitycsEnabled, $analyticsSampleRate) {
                $span->name = 'Elasticsearch.Client.__construct';
                $span->service = NAME;
                $span->type = Type::ELASTICSEARCH;
                $span->resource = _dd_build_resource_name('__construct', isset($args[0]) ? $args[0] : []);

                // Loading client methods
                global $DD_ES_GLOBAL;
                if ($DD_ES_GLOBAL['loaded']) {
                    return;
                }
                $DD_ES_GLOBAL['loaded'] = true;

                $trace = function (
                    $name,
                    $isTraceAnalyticsCandidate = false
                ) use (
                    $hookType,
                    $analitycsEnabled,
                    $analyticsSampleRate
                ) {
                    $analytics = ($isTraceAnalyticsCandidate && $analitycsEnabled) ? $analyticsSampleRate : null;
                    \dd_trace_method(
                        'Elasticsearch\Client',
                        $name,
                        [$hookType => function (SpanData $span, $methodArgs) use ($name, $analytics) {
                            $span->name = "Elasticsearch.Client.$name";

                            if (null !== $analytics) {
                                $span->meta[Tag::ANALYTICS_KEY] = $analytics;
                            }

                            $span->service = NAME;
                            $span->type = Type::ELASTICSEARCH;
                            $span->resource = _dd_build_resource_name(
                                $name,
                                isset($methodArgs[0]) ? $methodArgs[0] : []
                            );
                        },]
                    );
                };

                $trace('count');
                $trace('delete');
                $trace('exists');
                $trace('explain');
                $trace('get', true);
                $trace('index');
                $trace('scroll');
                $trace('search', true);
                $trace('update');
            },
        ]
    );
}

function _dd_integration_elasticsearch_simple()
{
    $methods = [
        ['Elasticsearch\Serializers\ArrayToJSONSerializer', 'serialize'],
        ['Elasticsearch\Serializers\ArrayToJSONSerializer', 'deserialize'],
        ['Elasticsearch\Serializers\EverythingToJSONSerializer', 'serialize'],
        ['Elasticsearch\Serializers\EverythingToJSONSerializer', 'deserialize'],
        ['Elasticsearch\Serializers\SmartSerializer', 'serialize'],
        ['Elasticsearch\Serializers\SmartSerializer', 'deserialize'],
    ];

    foreach ($methods as $method) {
        $class = $method[0];
        $name = $method[1];
        \dd_trace_method($class, $name, function (SpanData $span) use ($class, $name) {
            $operationName = str_replace('\\', '.', "$class.$name");
            $span->name = $operationName;
            $span->resource = $operationName;
            $span->service = NAME;
            $span->type = Type::ELASTICSEARCH;
        });
    }
}

function _dd_integration_elasticsearch_namespace()
{
    global $DD_ES_GLOBAL;

    $namespaces = [
        'IndicesNamespace' => [
            'analyze',
            'clearCache',
            'close',
            'create',
            'delete',
            'deleteAlias',
            'deleteMapping',
            'deleteTemplate',
            'deleteWarmer',
            'exists',
            'existsAlias',
            'existsTemplate',
            'existsType',
            'flush',
            'getAlias',
            'getAliases',
            'getFieldMapping',
            'getMapping',
            'getSettings',
            'getTemplate',
            'getWarmer',
            'open',
            'optimize',
            'putAlias',
            'putMapping',
            'putSettings',
            'putTemplate',
            'putWarmer',
            'recovery',
            'refresh',
            'segments',
            'snapshotIndex',
            'stats',
            'status',
            'updateAliases',
            'validateQuery',
        ],
        'CatNamespace' => [
            'aliases',
            'allocation',
            'count',
            'fielddata',
            'health',
            'help',
            'indices',
            'master',
            'nodes',
            'pendingTasks',
            'recovery',
            'shards',
            'threadPool',
        ],
        'SnapshotNamespace' => [
            'create',
            'createRepository',
            'delete',
            'deleteRepository',
            'get',
            'getRepository',
            'restore',
            'status',
        ],
        'ClusterNamespace' => [
            'getSettings',
            'health',
            'pendingTasks',
            'putSettings',
            'reroute',
            'state',
            'stats',
        ],
        'NodesNamespace' => [
            'hotThreads',
            'info',
            'shutdown',
            'stats',
        ],
    ];

    foreach ($namespaces as $namespace => $methods) {
        if (isset($DD_ES_GLOBAL[$namespace]) && $DD_ES_GLOBAL[$namespace] === true) {
            continue;
        }
        $DD_ES_GLOBAL[$namespace] = true;

        $class = 'Elasticsearch\Namespaces\\' . $namespace;
        \dd_trace_method($class, '__construct', function () use ($class, $namespace, $methods) {
            foreach ($methods as $name) {
                \dd_trace_method($class, $name, function (SpanData $span, $args) use ($namespace, $name) {
                    $params = [];
                    if (isset($args[0])) {
                        list($params) = $args;
                    }

                    $span->name = "Elasticsearch.$namespace.$name";
                    $span->resource = _dd_build_resource_name($name, $params);
                    $span->service = NAME;
                    $span->type = Type::ELASTICSEARCH;
                });
            }
            return false;
        });
    }
}

function _dd_integration_elasticsearch_endpoints()
{
    \dd_trace_method('Elasticsearch\Endpoints\AbstractEndpoint', 'performRequest', function (SpanData $span) {
        $span->name = "Elasticsearch.Endpoint.performRequest";
        $span->resource = 'performRequest';
        $span->service = NAME;
        $span->type = Type::ELASTICSEARCH;

        $span->meta[Tag::ELASTICSEARCH_URL] = $this->getURI();
        $span->meta[Tag::ELASTICSEARCH_METHOD] = $this->getMethod();
        if (is_array($this->params)) {
            $span->meta[Tag::ELASTICSEARCH_PARAMS] = json_encode($this->params);
        }
        if ($this->getMethod() === 'GET' && $body = $this->getBody()) {
            $span->meta[Tag::ELASTICSEARCH_BODY] = json_encode($body);
        }
    });
}


function dd_integration_elasticsearch_load()
{
    _dd_integration_elasticsearch_client(
        \dd_config_analytics_is_enabled() && \dd_config_integration_analytics_is_enabled(NAME),
        \dd_config_integration_analytics_sample_rate(NAME)
    );

    _dd_integration_elasticsearch_simple();
    _dd_integration_elasticsearch_namespace();
    _dd_integration_elasticsearch_endpoints();

    return SandboxedIntegration::LOADED;
}
