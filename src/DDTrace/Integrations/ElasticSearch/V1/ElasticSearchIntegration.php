<?php

namespace DDTrace\Integrations\ElasticSearch\V1;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class ElasticSearchIntegration extends Integration
{
    const NAME = 'elasticsearch';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        // Client operations
        $this->traceClientMethod('__construct');
        $this->traceClientMethod('count');
        $this->traceClientMethod('delete');
        $this->traceClientMethod('exists');
        $this->traceClientMethod('explain');
        $this->traceClientMethod('get', true);
        $this->traceClientMethod('index');
        $this->traceClientMethod('scroll');
        $this->traceClientMethod('search', true);
        $this->traceClientMethod('update');

        // Serializers
        $this->traceSimpleMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'serialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'deserialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'serialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'deserialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\SmartSerializer', 'serialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\SmartSerializer', 'deserialize');

        // IndicesNamespace operations
        $this->traceNamespaceMethod('IndicesNamespace', 'analyze');
        $this->traceNamespaceMethod('IndicesNamespace', 'clearCache');
        $this->traceNamespaceMethod('IndicesNamespace', 'close');
        $this->traceNamespaceMethod('IndicesNamespace', 'create');
        $this->traceNamespaceMethod('IndicesNamespace', 'delete');
        $this->traceNamespaceMethod('IndicesNamespace', 'deleteAlias');
        $this->traceNamespaceMethod('IndicesNamespace', 'deleteMapping');
        $this->traceNamespaceMethod('IndicesNamespace', 'deleteTemplate');
        $this->traceNamespaceMethod('IndicesNamespace', 'deleteWarmer');
        $this->traceNamespaceMethod('IndicesNamespace', 'exists');
        $this->traceNamespaceMethod('IndicesNamespace', 'existsAlias');
        $this->traceNamespaceMethod('IndicesNamespace', 'existsTemplate');
        $this->traceNamespaceMethod('IndicesNamespace', 'existsType');
        $this->traceNamespaceMethod('IndicesNamespace', 'flush');
        $this->traceNamespaceMethod('IndicesNamespace', 'getAlias');
        $this->traceNamespaceMethod('IndicesNamespace', 'getAliases');
        $this->traceNamespaceMethod('IndicesNamespace', 'getFieldMapping');
        $this->traceNamespaceMethod('IndicesNamespace', 'getMapping');
        $this->traceNamespaceMethod('IndicesNamespace', 'getSettings');
        $this->traceNamespaceMethod('IndicesNamespace', 'getTemplate');
        $this->traceNamespaceMethod('IndicesNamespace', 'getWarmer');
        $this->traceNamespaceMethod('IndicesNamespace', 'open');
        $this->traceNamespaceMethod('IndicesNamespace', 'optimize');
        $this->traceNamespaceMethod('IndicesNamespace', 'putAlias');
        $this->traceNamespaceMethod('IndicesNamespace', 'putMapping');
        $this->traceNamespaceMethod('IndicesNamespace', 'putSettings');
        $this->traceNamespaceMethod('IndicesNamespace', 'putTemplate');
        $this->traceNamespaceMethod('IndicesNamespace', 'putWarmer');
        $this->traceNamespaceMethod('IndicesNamespace', 'recovery');
        $this->traceNamespaceMethod('IndicesNamespace', 'refresh');
        $this->traceNamespaceMethod('IndicesNamespace', 'segments');
        $this->traceNamespaceMethod('IndicesNamespace', 'snapshotIndex');
        $this->traceNamespaceMethod('IndicesNamespace', 'stats');
        $this->traceNamespaceMethod('IndicesNamespace', 'status');
        $this->traceNamespaceMethod('IndicesNamespace', 'updateAliases');
        $this->traceNamespaceMethod('IndicesNamespace', 'validateQuery');

        // CatNamespace operations
        $this->traceNamespaceMethod('CatNamespace', 'aliases');
        $this->traceNamespaceMethod('CatNamespace', 'allocation');
        $this->traceNamespaceMethod('CatNamespace', 'count');
        $this->traceNamespaceMethod('CatNamespace', 'fielddata');
        $this->traceNamespaceMethod('CatNamespace', 'health');
        $this->traceNamespaceMethod('CatNamespace', 'help');
        $this->traceNamespaceMethod('CatNamespace', 'indices');
        $this->traceNamespaceMethod('CatNamespace', 'master');
        $this->traceNamespaceMethod('CatNamespace', 'nodes');
        $this->traceNamespaceMethod('CatNamespace', 'pendingTasks');
        $this->traceNamespaceMethod('CatNamespace', 'recovery');
        $this->traceNamespaceMethod('CatNamespace', 'shards');
        $this->traceNamespaceMethod('CatNamespace', 'threadPool');

        // SnapshotNamespace operations
        $this->traceNamespaceMethod('SnapshotNamespace', 'create');
        $this->traceNamespaceMethod('SnapshotNamespace', 'createRepository');
        $this->traceNamespaceMethod('SnapshotNamespace', 'delete');
        $this->traceNamespaceMethod('SnapshotNamespace', 'deleteRepository');
        $this->traceNamespaceMethod('SnapshotNamespace', 'get');
        $this->traceNamespaceMethod('SnapshotNamespace', 'getRepository');
        $this->traceNamespaceMethod('SnapshotNamespace', 'restore');
        $this->traceNamespaceMethod('SnapshotNamespace', 'status');

        // ClusterNamespace operations
        $this->traceNamespaceMethod('ClusterNamespace', 'getSettings');
        $this->traceNamespaceMethod('ClusterNamespace', 'health');
        $this->traceNamespaceMethod('ClusterNamespace', 'pendingTasks');
        $this->traceNamespaceMethod('ClusterNamespace', 'putSettings');
        $this->traceNamespaceMethod('ClusterNamespace', 'reroute');
        $this->traceNamespaceMethod('ClusterNamespace', 'state');
        $this->traceNamespaceMethod('ClusterNamespace', 'stats');

        // NodesNamespace operations
        $this->traceNamespaceMethod('NodesNamespace', 'hotThreads');
        $this->traceNamespaceMethod('NodesNamespace', 'info');
        $this->traceNamespaceMethod('NodesNamespace', 'shutdown');
        $this->traceNamespaceMethod('NodesNamespace', 'stats');

        // Endpoints
        \DDTrace\trace_method('Elasticsearch\Endpoints\AbstractEndpoint', 'performRequest', function (SpanData $span) {
            $span->name = "Elasticsearch.Endpoint.performRequest";
            $span->resource = 'performRequest';
            $span->service = ElasticSearchIntegration::NAME;
            $span->type = Type::ELASTICSEARCH;

            try {
                $span->meta[Tag::ELASTICSEARCH_URL] = $this->getURI();
                $span->meta[Tag::ELASTICSEARCH_METHOD] = $this->getMethod();
                if (is_array($this->params)) {
                    $span->meta[Tag::ELASTICSEARCH_PARAMS] = json_encode($this->params);
                }
                if ($this->getMethod() === 'GET' && $body = $this->getBody()) {
                    $span->meta[Tag::ELASTICSEARCH_BODY] = json_encode($body);
                }
            } catch (\Exception $ex) {
            }
        });

        return Integration::LOADED;
    }
    /**
     * @param string $name
     * @param bool $isTraceAnalyticsCandidate
     */
    public function traceClientMethod($name, $isTraceAnalyticsCandidate = false)
    {
        $integration = $this;
        $class = 'Elasticsearch\Client';

        /*
         * The Client `$params` array is mutated by extractArgument().
         * @see https://github.com/elastic/elasticsearch-php/blob/1.x/src/Elasticsearch/Client.php#L1710-L1723
         * Since the arguments passed to the tracing closure on PHP 7 are mutable,
         * the closure must be run _before_ the original call via 'prehook'.
        */
        $hookType = (PHP_MAJOR_VERSION >= 7) ? 'prehook' : 'posthook';

        \DDTrace\trace_method(
            $class,
            $name,
            [
                $hookType => function (SpanData $span, $args) use ($name, $isTraceAnalyticsCandidate, $integration) {
                    $span->name = "Elasticsearch.Client.$name";

                    if ($isTraceAnalyticsCandidate) {
                        $integration->addTraceAnalyticsIfEnabled($span);
                    }

                    $span->service = ElasticSearchIntegration::NAME;
                    $span->type = Type::ELASTICSEARCH;
                    $span->resource = ElasticSearchCommon::buildResourceName($name, isset($args[0]) ? $args[0] : []);
                }
            ]
        );
    }

    /**
     * @param string $class
     * @param string $name
     */
    public function traceSimpleMethod($class, $name)
    {
        \DDTrace\trace_method($class, $name, function (SpanData $span) use ($class, $name) {
            $operationName = str_replace('\\', '.', "$class.$name");
            $span->name = $operationName;
            $span->resource = $operationName;
            $span->service = ElasticSearchIntegration::NAME;
            $span->type = Type::ELASTICSEARCH;
        });
    }

    /**
     * @param string $namespace
     * @param string $name
     */
    public function traceNamespaceMethod($namespace, $name)
    {
        $class = 'Elasticsearch\Namespaces\\' . $namespace;

        \DDTrace\trace_method($class, $name, function (SpanData $span, $args) use ($namespace, $name) {
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$name";
            $span->resource = ElasticSearchCommon::buildResourceName($name, $params);
            $span->service = ElasticSearchIntegration::NAME;
            $span->type = Type::ELASTICSEARCH;
        });
    }
}
