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
     * Add instrumentation to Elasticsearch requests
     */
    public function init()
    {
        // Dynamically generate namespace traces to ensure forward compatibility with future ES versions
        $integration = $this;
        \DDTrace\trace_method('Elasticsearch\Client', '__construct', [
            "posthook" => function (SpanData $span) use (&$constructorCalled, $integration) {
                if (!$constructorCalled) {
                    $nsPattern = "(^Elasticsearch\\\\Namespaces\\\\([^\\\\]+Namespace)$)";
                    foreach ($this as $property) {
                        if (is_object($property) && preg_match($nsPattern, \get_class($property), $m)) {
                            foreach (get_class_methods($property) as $method) {
                                $integration->traceNamespaceMethod($m[1], $method);
                            }
                        }
                    }
                    $constructorCalled = true;
                }

                $span->name = "Elasticsearch.Client.__construct";
                $span->service = ElasticSearchIntegration::NAME;
                $span->type = Type::ELASTICSEARCH;
                $span->resource = "__construct";
            }
        ]);

        // Client operations
        $this->traceClientMethod('bulk');
        $this->traceClientMethod('clearScroll');
        $this->traceClientMethod('closePointInTime');
        $this->traceClientMethod('count');
        $this->traceClientMethod('create');
        $this->traceClientMethod('deleteByQuery');
        $this->traceClientMethod('deleteByQueryRethrottle');
        $this->traceClientMethod('deleteScript');
        $this->traceClientMethod('delete');
        $this->traceClientMethod('exists');
        $this->traceClientMethod('existsScource');
        $this->traceClientMethod('explain');
        $this->traceClientMethod('fieldCaps');
        $this->traceClientMethod('get', true);
        $this->traceClientMethod('getScript');
        $this->traceClientMethod('getScriptContext');
        $this->traceClientMethod('getScriptLanguages');
        $this->traceClientMethod('getSource');
        $this->traceClientMethod('index');
        $this->traceClientMethod('knnSearch', true);
        $this->traceClientMethod('mget', true);
        $this->traceClientMethod('msearch', true);
        $this->traceClientMethod('msearchTemplate', true);
        $this->traceClientMethod('mtermvectors');
        $this->traceClientMethod('openPointInTime');
        $this->traceClientMethod('ping');
        $this->traceClientMethod('putScript');
        $this->traceClientMethod('rankEval');
        $this->traceClientMethod('reindex');
        $this->traceClientMethod('reindexRethrottle');
        $this->traceClientMethod('renderSearchTemplate');
        $this->traceClientMethod('scriptsPainlessExecute');
        $this->traceClientMethod('scroll');
        $this->traceClientMethod('search', true);
        $this->traceClientMethod('searchMvt', true);
        $this->traceClientMethod('searchShards', true);
        $this->traceClientMethod('searchTemplate', true);
        $this->traceClientMethod('termsEnum', true);
        $this->traceClientMethod('termvectors');
        $this->traceClientMethod('update');
        $this->traceClientMethod('updateByQuery');
        $this->traceClientMethod('updateByQueryRethrottle');

        // Serializers
        $this->traceSimpleMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'serialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'deserialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'serialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'deserialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\SmartSerializer', 'serialize');
        $this->traceSimpleMethod('Elasticsearch\Serializers\SmartSerializer', 'deserialize');

        // Endpoints
        \DDTrace\trace_method('Elasticsearch\Endpoints\AbstractEndpoint', 'performRequest', function ($span) {
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
        \DDTrace\trace_method('Elasticsearch\Connections\Connection', 'performRequest', function ($span, $args) {
            $span->name = "Elasticsearch.Endpoint.performRequest";
            $span->resource = 'performRequest';
            $span->service = ElasticSearchIntegration::NAME;
            $span->type = Type::ELASTICSEARCH;

            $span->meta[Tag::ELASTICSEARCH_URL] = $args[1];
            $span->meta[Tag::ELASTICSEARCH_METHOD] = $args[0];
            $span->meta[Tag::ELASTICSEARCH_PARAMS] = json_encode($args[2]);
            $recordBody = $args[0] === 'GET' || preg_match("(/_m?search.*$)", $args[1]);
            if ($recordBody && null !== $body = $args[3]) {
                $span->meta[Tag::ELASTICSEARCH_BODY] = $body;
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
