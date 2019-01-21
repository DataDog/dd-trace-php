<?php

namespace DDTrace\Integrations\ElasticSearch\V1;

use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Environment;

/**
 * ElasticSearch driver v1 Integration
 */
class ElasticSearchIntegration
{
    const NAME = 'elasticsearch';
    const DEFAULT_SERVICE_NAME = 'elasticsearch';

    public static function load()
    {
        if (!class_exists('Elasticsearch\Client') || Environment::matchesPhpVersion('5.4')) {
            return Integration::NOT_LOADED;
        }

        // Client operations
        self::traceClientMethod('__construct');
        self::traceClientMethod('count');
        self::traceClientMethod('delete');
        self::traceClientMethod('exists');
        self::traceClientMethod('explain');
        self::traceClientMethod('get');
        self::traceClientMethod('index');
        self::traceClientMethod('scroll');
        self::traceClientMethod('search');
        self::traceClientMethod('update');

        // Serializers
        self::traceMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'serialize');
        self::traceMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'deserialize');
        self::traceMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'serialize');
        self::traceMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'deserialize');
        self::traceMethod('Elasticsearch\Serializers\SmartSerializer', 'serialize');
        self::traceMethod('Elasticsearch\Serializers\SmartSerializer', 'deserialize');

        // IndicesNamespace operations
        self::traceNamespaceMethod('IndicesNamespace', 'analyze');
        self::traceNamespaceMethod('IndicesNamespace', 'clearCache');
        self::traceNamespaceMethod('IndicesNamespace', 'close');
        self::traceNamespaceMethod('IndicesNamespace', 'create');
        self::traceNamespaceMethod('IndicesNamespace', 'delete');
        self::traceNamespaceMethod('IndicesNamespace', 'deleteAlias');
        self::traceNamespaceMethod('IndicesNamespace', 'deleteMapping');
        self::traceNamespaceMethod('IndicesNamespace', 'deleteTemplate');
        self::traceNamespaceMethod('IndicesNamespace', 'deleteWarmer');
        self::traceNamespaceMethod('IndicesNamespace', 'exists');
        self::traceNamespaceMethod('IndicesNamespace', 'existsAlias');
        self::traceNamespaceMethod('IndicesNamespace', 'existsTemplate');
        self::traceNamespaceMethod('IndicesNamespace', 'existsType');
        self::traceNamespaceMethod('IndicesNamespace', 'flush');
        self::traceNamespaceMethod('IndicesNamespace', 'getAlias');
        self::traceNamespaceMethod('IndicesNamespace', 'getAliases');
        self::traceNamespaceMethod('IndicesNamespace', 'getFieldMapping');
        self::traceNamespaceMethod('IndicesNamespace', 'getMapping');
        self::traceNamespaceMethod('IndicesNamespace', 'getSettings');
        self::traceNamespaceMethod('IndicesNamespace', 'getTemplate');
        self::traceNamespaceMethod('IndicesNamespace', 'getWarmer');
        self::traceNamespaceMethod('IndicesNamespace', 'open');
        self::traceNamespaceMethod('IndicesNamespace', 'optimize');
        self::traceNamespaceMethod('IndicesNamespace', 'putAlias');
        self::traceNamespaceMethod('IndicesNamespace', 'putMapping');
        self::traceNamespaceMethod('IndicesNamespace', 'putSettings');
        self::traceNamespaceMethod('IndicesNamespace', 'putTemplate');
        self::traceNamespaceMethod('IndicesNamespace', 'putWarmer');
        self::traceNamespaceMethod('IndicesNamespace', 'recovery');
        self::traceNamespaceMethod('IndicesNamespace', 'refresh');
        self::traceNamespaceMethod('IndicesNamespace', 'segments');
        self::traceNamespaceMethod('IndicesNamespace', 'snapshotIndex');
        self::traceNamespaceMethod('IndicesNamespace', 'stats');
        self::traceNamespaceMethod('IndicesNamespace', 'status');
        self::traceNamespaceMethod('IndicesNamespace', 'updateAliases');
        self::traceNamespaceMethod('IndicesNamespace', 'validateQuery');

        // CatNamespace operations
        self::traceNamespaceMethod('CatNamespace', 'aliases');
        self::traceNamespaceMethod('CatNamespace', 'allocation');
        self::traceNamespaceMethod('CatNamespace', 'count');
        self::traceNamespaceMethod('CatNamespace', 'fielddata');
        self::traceNamespaceMethod('CatNamespace', 'health');
        self::traceNamespaceMethod('CatNamespace', 'help');
        self::traceNamespaceMethod('CatNamespace', 'indices');
        self::traceNamespaceMethod('CatNamespace', 'master');
        self::traceNamespaceMethod('CatNamespace', 'nodes');
        self::traceNamespaceMethod('CatNamespace', 'pendingTasks');
        self::traceNamespaceMethod('CatNamespace', 'recovery');
        self::traceNamespaceMethod('CatNamespace', 'shards');
        self::traceNamespaceMethod('CatNamespace', 'threadPool');

        // SnapshotNamespace operations
        self::traceNamespaceMethod('SnapshotNamespace', 'create');
        self::traceNamespaceMethod('SnapshotNamespace', 'createRepository');
        self::traceNamespaceMethod('SnapshotNamespace', 'delete');
        self::traceNamespaceMethod('SnapshotNamespace', 'deleteRepository');
        self::traceNamespaceMethod('SnapshotNamespace', 'get');
        self::traceNamespaceMethod('SnapshotNamespace', 'getRepository');
        self::traceNamespaceMethod('SnapshotNamespace', 'restore');
        self::traceNamespaceMethod('SnapshotNamespace', 'status');

        // ClusterNamespace operations
        self::traceNamespaceMethod('ClusterNamespace', 'getSettings');
        self::traceNamespaceMethod('ClusterNamespace', 'health');
        self::traceNamespaceMethod('ClusterNamespace', 'pendingTasks');
        self::traceNamespaceMethod('ClusterNamespace', 'putSettings');
        self::traceNamespaceMethod('ClusterNamespace', 'reroute');
        self::traceNamespaceMethod('ClusterNamespace', 'state');
        self::traceNamespaceMethod('ClusterNamespace', 'stats');

        // NodesNamespace operations
        self::traceNamespaceMethod('NodesNamespace', 'hotThreads');
        self::traceNamespaceMethod('NodesNamespace', 'info');
        self::traceNamespaceMethod('NodesNamespace', 'shutdown');
        self::traceNamespaceMethod('NodesNamespace', 'stats');

        // Endpoints
        dd_trace('Elasticsearch\Endpoints\AbstractEndpoint', 'performRequest', function () {
            $args = func_get_args();
            $tracer = GlobalTracer::get();
            $scope = $tracer->startActiveSpan("Elasticsearch.Endpoint.performRequest");
            $span = $scope->getSpan();

            $span->setTag(Tag::SERVICE_NAME, ElasticSearchIntegration::DEFAULT_SERVICE_NAME);
            $span->setTag(Tag::SPAN_TYPE, Type::ELASTICSEARCH);

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                // Some endpoints can throw exception during getURI() if some parameters are missing, so
                // make sure that the uri is read within the try-catch-finally block.
                $span->setTag(Tag::RESOURCE_NAME, 'performRequest');
                $span->setTag(Tag::ELASTICSEARCH_URL, $this->getURI());
                $span->setTag(Tag::ELASTICSEARCH_METHOD, $this->getMethod());
                if (is_array($this->params)) {
                    $span->setTag(Tag::ELASTICSEARCH_PARAMS, json_encode($this->params));
                }
                if ($this->getMethod() === 'GET' && $body = $this->getBody()) {
                    $span->setTag(Tag::ELASTICSEARCH_BODY, json_encode($this->getBody()));
                }
                $result = call_user_func_array([$this, 'performRequest'], $args);
            } catch (\Exception $ex) {
                $thrown = $ex;
                if ($span instanceof Span) {
                    $span->setError($ex);
                }
            }
            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        return Integration::LOADED;
    }

    /**
     * @param string $name
     */
    public static function traceClientMethod($name)
    {
        $class = 'Elasticsearch\Client';
        if (!method_exists($class, $name)) {
            return;
        }

        dd_trace($class, $name, function () use ($name) {
            $args = func_get_args();
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }
            $tracer = GlobalTracer::get();
            $scope = $tracer->startActiveSpan("Elasticsearch.Client.$name");
            $span = $scope->getSpan();

            $span->setTag(Tag::SERVICE_NAME, ElasticSearchIntegration::DEFAULT_SERVICE_NAME);
            $span->setTag(Tag::SPAN_TYPE, Type::ELASTICSEARCH);
            $span->setTag(Tag::RESOURCE_NAME, ElasticSearchIntegration::buildResourceName($name, $params));

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = call_user_func_array([$this, $name], $args);
            } catch (\Exception $ex) {
                $thrown = $ex;
                if ($span instanceof Span) {
                    $span->setError($ex);
                }
            }
            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });
    }

    /**
     * @param string $class
     * @param array $methods
     */
    public static function traceSimpleMethodsCall($class, array $methods)
    {
        foreach ($methods as $method) {
            ElasticSearchIntegration::traceMethod($class, $method);
        }
    }

    /**
     * @param string $class
     * @param string $name
     */
    public static function traceMethod($class, $name)
    {
        if (!method_exists($class, $name)) {
            return;
        }

        dd_trace($class, $name, function () use ($class, $name) {
            $args = func_get_args();

            $tracer = GlobalTracer::get();
            $operationName = str_replace('\\', '.', "$class.$name");
            $scope = $tracer->startActiveSpan($operationName);
            $span = $scope->getSpan();

            $span->setTag(Tag::SERVICE_NAME, ElasticSearchIntegration::DEFAULT_SERVICE_NAME);
            $span->setTag(Tag::SPAN_TYPE, Type::ELASTICSEARCH);
            $span->setTag(Tag::RESOURCE_NAME, $operationName);

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = call_user_func_array([$this, $name], $args);
            } catch (\Exception $ex) {
                $thrown = $ex;
                if ($span instanceof Span) {
                    $span->setError($ex);
                }
            }
            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });
    }

    /**
     * @param string $namespace
     * @param string $name
     */
    public static function traceNamespaceMethod($namespace, $name)
    {
        $class = 'Elasticsearch\Namespaces\\' . $namespace;
        if (!method_exists($class, $name)) {
            return;
        }

        dd_trace($class, $name, function () use ($namespace, $name) {
            $args = func_get_args();
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }
            $tracer = GlobalTracer::get();
            $scope = $tracer->startActiveSpan("Elasticsearch.$namespace.$name");
            $span = $scope->getSpan();

            $span->setTag(Tag::SERVICE_NAME, ElasticSearchIntegration::DEFAULT_SERVICE_NAME);
            $span->setTag(Tag::SPAN_TYPE, Type::ELASTICSEARCH);
            $span->setTag(Tag::RESOURCE_NAME, ElasticSearchIntegration::buildResourceName($name, $params));

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = call_user_func_array([$this, $name], $args);
            } catch (\Exception $ex) {
                $thrown = $ex;
                if ($span instanceof Span) {
                    $span->setError($ex);
                }
            }
            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });
    }

    /**
     * @param string $methodName
     * @param array|null $params
     * @return string
     */
    public static function buildResourceName($methodName, $params)
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
}
