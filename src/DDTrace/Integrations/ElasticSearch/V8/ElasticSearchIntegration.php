<?php

namespace DDTrace\Integrations\ElasticSearch\V8;

use DDTrace\HookData;
use DDTrace\Integrations\ElasticSearch\ElasticSearchCommon;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class ElasticSearchIntegration extends Integration
{
    const NAME = 'elasticsearch';

    public static $logNextBody = false;
    public static $constructorCalled = false;

    /**
     * Add instrumentation to Elasticsearch requests
     */
    public static function init(): int
    {
        // Dynamically generate namespace traces to ensure forward compatibility with future ES versions
        \DDTrace\trace_method('Elastic\Elasticsearch\Client', '__construct', [
            "posthook" => function (SpanData $span) {
                if (!ElasticSearchIntegration::$constructorCalled) {
                    foreach (get_class_methods('Elastic\Elasticsearch\Traits\NamespaceTrait') as $method) {
                        $hook = function (HookData $hook) use ($method) {
                            $ret = $hook->returned;
                            \DDTrace\remove_hook($hook->id);
                            $class = get_class($ret);
                            foreach (get_class_methods($ret) as $method) {
                                ElasticSearchIntegration::traceNamespaceMethod($class, $method);
                            }
                        };

                        \DDTrace\install_hook("Elastic\Elasticsearch\Client::$method", null, $hook);
                    }
                    foreach (get_class_methods('Elastic\Elasticsearch\Traits\ClientEndpointsTrait') as $method) {
                        $analyticsMethods = [
                            "count",
                            "fieldCaps",
                            "explain",
                            "get",
                            "mget",
                            "termsEnum",
                            "termvectors",
                            "mtermvectors",
                            "rankEval",
                            "scriptsPainlessExecute"
                        ];
                        $traceAnalytics = stripos($method, "search") !== false || in_array($method, $analyticsMethods);
                        ElasticSearchIntegration::traceClientMethod($method, $traceAnalytics);
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

        // Serializers
        self::traceSimpleMethod('Elastic\Transport\Serializer\CsvSerializer', 'serialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\CsvSerializer', 'unserialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\JsonSerializer', 'serialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\JsonSerializer', 'unserialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\NDJsonSerializer', 'serialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\NDJsonSerializer', 'unserialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\TextSerializer', 'serialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\TextSerializer', 'unserialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\XmlSerializer', 'serialize');
        self::traceSimpleMethod('Elastic\Transport\Serializer\XmlSerializer', 'unserialize');

        // Endpoints
        $hook = function ($span, $args) {
            $span->name = "Elasticsearch.Endpoint.performRequest";
            $span->resource = 'performRequest';
            Integration::handleInternalSpanServiceName($span, ElasticSearchIntegration::NAME);
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::COMPONENT] = ElasticSearchIntegration::NAME;

            /** @var Psr\Http\Message\RequestInterface $request */
            $request = $args[0];

            try {
                $uri = $request->getUri();
                $query = $uri->getQuery();
                $span->meta[Tag::ELASTICSEARCH_URL] = (string)$uri->withQuery("");
                $span->meta[Tag::ELASTICSEARCH_METHOD] = $request->getMethod();
                $span->meta[Tag::SPAN_KIND] = 'client';
                if ($query) {
                    parse_str($query, $queryParts);
                    $span->meta[Tag::ELASTICSEARCH_PARAMS] = json_encode($queryParts);
                }
                if (ElasticSearchIntegration::$logNextBody && ($body = $request->getBody()) && $body->isSeekable()) {
                    $pos = $body->tell();
                    $body->seek(0);
                    $span->meta[Tag::ELASTICSEARCH_BODY] = $body->getContents();
                    $body->seek($pos);
                }
            } catch (\Exception $ex) {
            }
        };
        \DDTrace\trace_method('Elastic\Elasticsearch\Client', 'sendRequest', $hook);

        return Integration::LOADED;
    }
    /**
     * @param string $name
     * @param bool $isTraceAnalyticsCandidate
     */
    public static function traceClientMethod($name, $isTraceAnalyticsCandidate = false)
    {
        $class = 'Elastic\Elasticsearch\Client';

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
                'prehook' => function (SpanData $span, $args) use ($name, $isTraceAnalyticsCandidate) {
                    $span->name = "Elasticsearch.Client.$name";

                    if ($isTraceAnalyticsCandidate) {
                        ElasticSearchIntegration::addTraceAnalyticsIfEnabled($span);
                        ElasticSearchIntegration::$logNextBody = true;
                    }

                    $span->meta[Tag::SPAN_KIND] = 'client';
                    Integration::handleInternalSpanServiceName($span, ElasticSearchIntegration::NAME);
                    $span->type = Type::ELASTICSEARCH;
                    $span->resource = ElasticSearchCommon::buildResourceName($name, isset($args[0]) ? $args[0] : []);
                    $span->meta[Tag::COMPONENT] = ElasticSearchIntegration::NAME;
                },
                'posthook' => function () {
                    ElasticSearchIntegration::$logNextBody = false;
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
        \DDTrace\trace_method($class, $name, function (SpanData $span) use ($class, $name) {
            $operationName = str_replace('\\', '.', "$class.$name");
            $span->name = $operationName;
            $span->resource = $operationName;
            Integration::handleInternalSpanServiceName($span, ElasticSearchIntegration::NAME);
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::COMPONENT] = ElasticSearchIntegration::NAME;
        });
    }

    /**
     * @param string $namespace
     * @param string $name
     */
    public static function traceNamespaceMethod($class, $name)
    {
        $namespace = substr(strrchr($class, "\\"), 1);

        \DDTrace\trace_method($class, $name, function (SpanData $span, $args) use ($namespace, $name) {
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$name";
            $span->resource = ElasticSearchCommon::buildResourceName($name, $params);
            Integration::handleInternalSpanServiceName($span, ElasticSearchIntegration::NAME);
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::COMPONENT] = ElasticSearchIntegration::NAME;
        });
    }
}
