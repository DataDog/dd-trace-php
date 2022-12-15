<?php

namespace DDTrace\Integrations\ElasticSearch\V8;

use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchCommon;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class ElasticSearchIntegration extends Integration
{
    const NAME = 'elasticsearch';

    public $logNextBody = false;

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
        \DDTrace\trace_method('Elastic\Elasticsearch\Client', '__construct', [
            "posthook" => function (SpanData $span) use (&$constructorCalled, $integration) {
                if (!$constructorCalled) {
                    foreach (get_class_methods('Elastic\Elasticsearch\Traits\NamespaceTrait') as $method) {
                        $hook = function ($obj, $scope, $args, $ret) use ($integration, $method) {
                            \dd_untrace('Elastic\Elasticsearch\Traits\NamespaceTrait', $method);
                            $class = get_class($ret);
                            foreach (get_class_methods($ret) as $method) {
                                $integration->traceNamespaceMethod($class, $method);
                            }
                        };
                        \DDTrace\hook_method('Elastic\Elasticsearch\Client', $method, null, $hook);
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
                        $integration->traceClientMethod($method, $traceAnalytics);
                    }
                    $constructorCalled = true;
                }

                $span->name = "Elasticsearch.Client.__construct";
                $span->service = ElasticSearchIntegration::NAME;
                $span->type = Type::ELASTICSEARCH;
                $span->resource = "__construct";
                $span->meta[Tag::COMPONENT] = $this->getName();
            }
        ]);

        // Serializers
        $this->traceSimpleMethod('Elastic\Transport\Serializer\CsvSerializer', 'serialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\CsvSerializer', 'unserialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\JsonSerializer', 'serialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\JsonSerializer', 'unserialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\NDJsonSerializer', 'serialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\NDJsonSerializer', 'unserialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\TextSerializer', 'serialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\TextSerializer', 'unserialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\XmlSerializer', 'serialize');
        $this->traceSimpleMethod('Elastic\Transport\Serializer\XmlSerializer', 'unserialize');

        // Endpoints
        $hook = function ($span, $args) use ($integration) {
            $span->name = "Elasticsearch.Endpoint.performRequest";
            $span->resource = 'performRequest';
            $span->service = ElasticSearchIntegration::NAME;
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::COMPONENT] = $this->getName();

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
                if ($integration->logNextBody && ($body = $request->getBody()) && $body->isSeekable()) {
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
    public function traceClientMethod($name, $isTraceAnalyticsCandidate = false)
    {
        $integration = $this;
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
                'prehook' => function (SpanData $span, $args) use ($name, $isTraceAnalyticsCandidate, $integration) {
                    $span->name = "Elasticsearch.Client.$name";

                    if ($isTraceAnalyticsCandidate) {
                        $integration->addTraceAnalyticsIfEnabled($span);
                        $integration->logNextBody = true;
                    }

                    $span->meta[Tag::SPAN_KIND] = 'client';
                    $span->service = ElasticSearchIntegration::NAME;
                    $span->type = Type::ELASTICSEARCH;
                    $span->resource = ElasticSearchCommon::buildResourceName($name, isset($args[0]) ? $args[0] : []);
                    $span->meta[Tag::COMPONENT] = $this->getName();
                },
                'posthook' => function () use ($integration) {
                    $integration->logNextBody = false;
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
            $span->meta[Tag::COMPONENT] = $this->getName();
        });
    }

    /**
     * @param string $namespace
     * @param string $name
     */
    public function traceNamespaceMethod($class, $name)
    {
        $namespace = substr(strrchr($class, "\\"), 1);

        \DDTrace\trace_method($class, $name, function (SpanData $span, $args) use ($namespace, $name) {
            $params = [];
            if (isset($args[0])) {
                list($params) = $args;
            }

            $span->name = "Elasticsearch.$namespace.$name";
            $span->resource = ElasticSearchCommon::buildResourceName($name, $params);
            $span->service = ElasticSearchIntegration::NAME;
            $span->type = Type::ELASTICSEARCH;
            $span->meta[Tag::COMPONENT] = $this->getName();
        });
    }
}
