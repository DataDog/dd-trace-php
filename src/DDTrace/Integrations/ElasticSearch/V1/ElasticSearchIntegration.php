<?php

namespace DDTrace\Integrations\ElasticSearch\V1;

use DDTrace\Integrations\ElasticSearch\ElasticSearchCommon;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class ElasticSearchIntegration extends Integration
{
    const NAME = 'elasticsearch';

    public static $constructorCalled = false;

    /**
     * Add instrumentation to Elasticsearch requests
     */
    public static function init(): int
    {
        // Dynamically generate namespace traces to ensure forward compatibility with future ES versions
        \DDTrace\trace_method('Elasticsearch\Client', '__construct', [
            "posthook" => function (SpanData $span) {
                if (!ElasticSearchIntegration::$constructorCalled) {
                    $nsPattern = "(^Elasticsearch\\\\Namespaces\\\\([^\\\\]+Namespace)$)";
                    foreach ($this as $property) {
                        if (is_object($property) && preg_match($nsPattern, \get_class($property), $m)) {
                            foreach (get_class_methods($property) as $method) {
                                ElasticSearchIntegration::traceNamespaceMethod($m[1], $method);
                            }
                        }
                    }
                    ElasticSearchIntegration::$constructorCalled = true;
                }

                $span->name = "Elasticsearch.Client.__construct";
                Integration::handleInternalSpanServiceName($span, ElasticSearchIntegration::NAME);
                $span->type = Type::ELASTICSEARCH;
                $span->resource = "__construct";
                $span->meta[Tag::COMPONENT] = ElasticSearchIntegration::NAME;
            }
        ]);

        // Client operations
        self::traceClientMethod('bulk');
        self::traceClientMethod('clearScroll');
        self::traceClientMethod('closePointInTime');
        self::traceClientMethod('count');
        self::traceClientMethod('create');
        self::traceClientMethod('deleteByQuery');
        self::traceClientMethod('deleteByQueryRethrottle');
        self::traceClientMethod('deleteScript');
        self::traceClientMethod('delete');
        self::traceClientMethod('exists');
        self::traceClientMethod('existsScource');
        self::traceClientMethod('explain');
        self::traceClientMethod('fieldCaps');
        self::traceClientMethod('get', true);
        self::traceClientMethod('getScript');
        self::traceClientMethod('getScriptContext');
        self::traceClientMethod('getScriptLanguages');
        self::traceClientMethod('getSource');
        self::traceClientMethod('index');
        self::traceClientMethod('knnSearch', true);
        self::traceClientMethod('mget', true);
        self::traceClientMethod('msearch', true);
        self::traceClientMethod('msearchTemplate', true);
        self::traceClientMethod('mtermvectors');
        self::traceClientMethod('openPointInTime');
        self::traceClientMethod('ping');
        self::traceClientMethod('putScript');
        self::traceClientMethod('rankEval');
        self::traceClientMethod('reindex');
        self::traceClientMethod('reindexRethrottle');
        self::traceClientMethod('renderSearchTemplate');
        self::traceClientMethod('scriptsPainlessExecute');
        self::traceClientMethod('scroll');
        self::traceClientMethod('search', true);
        self::traceClientMethod('searchMvt', true);
        self::traceClientMethod('searchShards', true);
        self::traceClientMethod('searchTemplate', true);
        self::traceClientMethod('termsEnum', true);
        self::traceClientMethod('termvectors');
        self::traceClientMethod('update');
        self::traceClientMethod('updateByQuery');
        self::traceClientMethod('updateByQueryRethrottle');

        // Serializers
        self::traceSimpleMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'serialize');
        self::traceSimpleMethod('Elasticsearch\Serializers\ArrayToJSONSerializer', 'deserialize');
        self::traceSimpleMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'serialize');
        self::traceSimpleMethod('Elasticsearch\Serializers\EverythingToJSONSerializer', 'deserialize');
        self::traceSimpleMethod('Elasticsearch\Serializers\SmartSerializer', 'serialize');
        self::traceSimpleMethod('Elasticsearch\Serializers\SmartSerializer', 'deserialize');

        // Endpoints
        \DDTrace\trace_method('Elasticsearch\Endpoints\AbstractEndpoint', 'performRequest', function ($span) {
            $span->name = "Elasticsearch.Endpoint.performRequest";
            $span->resource = 'performRequest';
            Integration::handleInternalSpanServiceName($span, ElasticSearchIntegration::NAME);
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::SPAN_KIND] = 'client';
            $span->meta[Tag::COMPONENT] = ElasticSearchIntegration::NAME;

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
        \DDTrace\trace_method('Elasticsearch\Connections\Connection', 'performRequest', static function ($span, $args) {
            $span->name = "Elasticsearch.Endpoint.performRequest";
            $span->resource = 'performRequest';
            Integration::handleInternalSpanServiceName($span, self::NAME);
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::SPAN_KIND] = 'client';
            $span->meta[Tag::COMPONENT] = self::NAME;

            $span->meta[Tag::ELASTICSEARCH_URL] = $args[1];
            $span->meta[Tag::ELASTICSEARCH_METHOD] = $args[0];
            $span->meta[Tag::ELASTICSEARCH_PARAMS] = json_encode($args[2]);
            $recordBody = $args[0] === 'GET' || preg_match("(/_m?search.*$)", $args[1]);
            if ($recordBody && null !== $body = $args[3]) {
                $span->meta[Tag::ELASTICSEARCH_BODY] = json_encode($body);
            }
        });

        return Integration::LOADED;
    }
    /**
     * @param string $name
     * @param bool $isTraceAnalyticsCandidate
     */
    public static function traceClientMethod($name, $isTraceAnalyticsCandidate = false)
    {
        $class = 'Elasticsearch\Client';

        /*
         * The Client `$params` array is mutated by extractArgument().
         * @see https://github.com/elastic/elasticsearch-php/blob/1.x/src/Elasticsearch/Client.php#L1710-L1723
         * Since the arguments passed to the tracing closure on PHP 7 are mutable,
         * the closure must be run _before_ the original call via 'prehook'.
        */
        \DDTrace\trace_method(
            $class,
            $name,
            [
                'prehook' => static function (SpanData $span, $args) use ($name, $isTraceAnalyticsCandidate) {
                    $span->name = "Elasticsearch.Client.$name";

                    if ($isTraceAnalyticsCandidate) {
                        self::addTraceAnalyticsIfEnabled($span);
                    }

                    $span->meta[Tag::SPAN_KIND] = 'client';
                    Integration::handleInternalSpanServiceName($span, self::NAME);
                    $span->type = Type::ELASTICSEARCH;
                    $span->resource = ElasticSearchCommon::buildResourceName($name, isset($args[0]) ? $args[0] : []);
                    $span->meta[Tag::COMPONENT] = self::NAME;
                }
            ]
        );
    }

    /**
     * @param string $class
     * @param string $name
     */
    public static function traceSimpleMethod($class, $name)
    {
        \DDTrace\trace_method($class, $name, static function (SpanData $span) use ($class, $name) {
            $operationName = str_replace('\\', '.', "$class.$name");
            $span->name = $operationName;
            $span->resource = $operationName;
            Integration::handleInternalSpanServiceName($span, self::NAME);
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::COMPONENT] = self::NAME;
        });
    }

    /**
     * @param string $namespace
     * @param string $name
     */
    public static function traceNamespaceMethod($namespace, $name)
    {
        $class = 'Elasticsearch\Namespaces\\' . $namespace;

        \DDTrace\trace_method($class, $name, static function (SpanData $span, $args) use ($namespace, $name) {
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$name";
            $span->resource = ElasticSearchCommon::buildResourceName($name, $params);
            Integration::handleInternalSpanServiceName($span, self::NAME);
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::COMPONENT] = self::NAME;
        });
    }
}
