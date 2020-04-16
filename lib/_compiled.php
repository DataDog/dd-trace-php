<?php
namespace {
function _dd_config_string($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }
    return trim($value);
}
function _dd_config_bool($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }
    $value = strtolower($value);
    if ($value === '1' || $value === 'true') {
        return true;
    } elseif ($value === '0' || $value === 'false') {
        return false;
    } else {
        return $default;
    }
}
function _dd_config_float($value, $default, $min = null, $max = null)
{
    if (false === $value || null === $value) {
        return $default;
    }
    $value = strtolower($value);
    if (is_numeric($value)) {
        $floatValue = (float) $value;
    } else {
        $floatValue = (float) $default;
    }
    if (null !== $min && $floatValue < $min) {
        $floatValue = $min;
    }
    if (null !== $max && $floatValue > $max) {
        $floatValue = $max;
    }
    return $floatValue;
}
function _dd_config_json($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }
    $parsed = \json_decode($this->stringValue('trace.sampling.rules'), true);
    if (false === $parsed) {
        $parsed = $default;
    }
    return $parsed;
}
function _dd_config_indexed_array($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }
    return array_map(function ($entry) {
        return strtolower(trim($entry));
    }, explode(',', $value));
}
function _dd_config_associative_array($value, $default)
{
    if (false === $value || null === $value) {
        return $default;
    }
    $result = [];
    $elements = explode(',', $value);
    foreach ($elements as $element) {
        $keyAndValue = explode(':', $element);
        if (count($keyAndValue) !== 2) {
            continue;
        }
        $keyFragment = trim($keyAndValue[0]);
        $valueFragment = trim($keyAndValue[1]);
        if (empty($keyFragment)) {
            continue;
        }
        $result[$keyFragment] = $valueFragment;
    }
    return $result;
}
function dd_config_trace_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_ENABLED'), true);
}
function dd_config_debug_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_DEBUG'), false);
}
function dd_config_distributed_tracing_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_DISTRIBUTED_TRACING'), true);
}
function dd_config_analytics_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_ANALYTICS_ENABLED'), true);
}
function dd_config_priority_sampling_is_enabled()
{
    return \dd_config_analytics_is_enabled() && \_dd_config_bool(\dd_trace_env_config('DD_PRIORITY_SAMPLING'), true);
}
function dd_config_hostname_reporting_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME'), false);
}
function dd_config_url_resource_name_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME'), true);
}
function dd_config_http_client_split_by_domain_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN'), false);
}
function dd_config_sandbox_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_TRACE_SANDBOX_ENABLED'), true);
}
function dd_config_autofinish_span_is_enabled()
{
    return \_dd_config_bool(\dd_trace_env_config('DD_AUTOFINISH_SPANS'), false);
}
function dd_config_sampling_rate()
{
    return \_dd_config_float(\dd_trace_env_config('DD_TRACE_SAMPLE_RATE'), 1.0, 0.0, 1.0);
}
function dd_config_sampling_rules()
{
    $json = \_dd_config_json(\dd_trace_env_config('DD_TRACE_SAMPLING_RULES'), []);
    // We do a proper parsing here to make sure that once the sampling rules leave this method
    // they are always properly defined.
    foreach ($json as &$rule) {
        if (!is_array($rule) || !isset($rule['sample_rate'])) {
            continue;
        }
        $service = isset($rule['service']) ? strval($rule['service']) : '.*';
        $name = isset($rule['name']) ? strval($rule['name']) : '.*';
        $rate = isset($rule['sample_rate']) ? floatval($rule['sample_rate']) : 1.0;
        $this->samplingRulesCache[] = ['service' => $service, 'name' => $name, 'sample_rate' => $rate];
    }
    return $json;
}
function dd_config_integration_is_enabled($name)
{
    $disabled = \dd_config_disabled_integrations();
    return \dd_config_trace_is_enabled() && !in_array($name, $disabled);
}
function dd_config_integration_analytics_is_enabled($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return \_dd_config_bool(\dd_trace_env_config("DD_{$integrationNameForEnv}_ANALYTICS_ENABLED"), false);
}
function dd_config_integration_analytics_sample_rate($name)
{
    $integrationNameForEnv = strtoupper(str_replace('-', '_', trim($name)));
    return \_dd_config_float(\dd_trace_env_config("DD_{$integrationNameForEnv}_ANALYTICS_SAMPLE_RATE"), 1.0);
}
function dd_config_disabled_integrations()
{
    return \_dd_config_indexed_array(\dd_trace_env_config('DD_INTEGRATIONS_DISABLED'), []);
}
function dd_config_global_tags()
{
    return \_dd_config_associative_array(\dd_trace_env_config('DD_TRACE_GLOBAL_TAGS'), []);
}
function dd_config_service_mapping()
{
    return \_dd_config_associative_array(\dd_trace_env_config('DD_SERVICE_MAPPING'), []);
}
function dd_config_app_name($default = '')
{
    return \_dd_config_string(\dd_trace_env_config('DD_SERVICE_NAME'), $default);
}
}

namespace DDTrace\Integrations\Guzzle {
use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
const NAME = 'guzzle';
// This will disappear once we do this check at c-level
$DD_GUZZLE_GLOBAL = ['loaded' => false];
function _dd_intgs_guzzle_request_info(SpanData $span, $request)
{
    if (\is_a($request, 'Psr\\Http\\Message\\RequestInterface')) {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $url = $request->getUri();
        if (Configuration::get()->isHttpClientSplitByDomain()) {
            $span->service = Urls::hostnameForTag($url);
        }
        $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
        $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
    } elseif (\is_a($request, 'GuzzleHttp\\Message\\RequestInterface')) {
        /** @var \GuzzleHttp\Message\RequestInterface $request */
        $url = $request->getUrl();
        if (Configuration::get()->isHttpClientSplitByDomain()) {
            $span->service = Urls::hostnameForTag($url);
        }
        $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
        $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
    }
}
function dd_integration_guzzle_load()
{
    $tracer = GlobalTracer::get();
    $rootScope = $tracer->getRootScope();
    if (!$rootScope) {
        return SandboxedIntegration::NOT_LOADED;
    }
    $service = Configuration::get()->appName(NAME);
    \dd_trace_method('GuzzleHttp\\Client', '__construct', function () use($service) {
        global $DD_GUZZLE_GLOBAL;
        if ($DD_GUZZLE_GLOBAL['loaded']) {
            return false;
        }
        $DD_GUZZLE_GLOBAL['loaded'] = true;
        /* Until we support both pre- and post- hooks on the same function, do
         * not send distributed tracing headers; curl will almost guaranteed do
         * it for us anyway. Just do a post-hook to get the response.
         */
        \dd_trace_method('GuzzleHttp\\Client', 'send', function (SpanData $span, $args, $retval) use($service) {
            $span->resource = 'send';
            $span->name = 'GuzzleHttp\\Client.send';
            $span->service = $service;
            $span->type = Type::HTTP_CLIENT;
            if (isset($args[0])) {
                _dd_intgs_guzzle_request_info($span, $args[0]);
            }
            if (isset($retval)) {
                $response = $retval;
                if (\is_a($response, 'GuzzleHttp\\Message\\ResponseInterface')) {
                    /** @var \GuzzleHttp\Message\ResponseInterface $response */
                    $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                } elseif (\is_a($response, 'Psr\\Http\\Message\\ResponseInterface')) {
                    /** @var \Psr\Http\Message\ResponseInterface $response */
                    $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                } elseif (\is_a($response, 'GuzzleHttp\\Promise\\PromiseInterface')) {
                    /** @var \GuzzleHttp\Promise\PromiseInterface $response */
                    $response->then(function (\Psr\Http\Message\ResponseInterface $response) use($span) {
                        $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                    });
                }
            }
        });
        \dd_trace_method('GuzzleHttp\\Client', 'transfer', function (SpanData $span, $args, $retval) use($service) {
            $span->resource = 'transfer';
            $span->name = 'GuzzleHttp\\Client.transfer';
            $span->service = $service;
            $span->type = Type::HTTP_CLIENT;
            if (isset($args[0])) {
                _dd_intgs_guzzle_request_info($span, $args[0]);
            }
            if (isset($retval)) {
                $response = $retval;
                if (\is_a($response, 'GuzzleHttp\\Promise\\PromiseInterface')) {
                    /** @var \GuzzleHttp\Promise\PromiseInterface $response */
                    $response->then(function (\Psr\Http\Message\ResponseInterface $response) use($span) {
                        $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                    });
                }
            }
        });
        return false;
    });
    return SandboxedIntegration::LOADED;
}
}

namespace DDTrace\Integrations\ElasticSearch {
use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
const NAME = 'elasticsearch';
// This will disappear once we do this check at c-level
$DD_ES_GLOBAL = ['loaded' => false];
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
    $hookType = PHP_MAJOR_VERSION >= 7 ? 'prehook' : 'posthook';
    \dd_trace_method('Elasticsearch\\Client', '__construct', [$hookType => function (SpanData $span, $args) use($hookType, $analitycsEnabled, $analyticsSampleRate) {
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
        $trace = function ($name, $isTraceAnalyticsCandidate = false) use($hookType, $analitycsEnabled, $analyticsSampleRate) {
            $analytics = $isTraceAnalyticsCandidate && $analitycsEnabled ? $analyticsSampleRate : null;
            \dd_trace_method('Elasticsearch\\Client', $name, [$hookType => function (SpanData $span, $methodArgs) use($name, $analytics) {
                $span->name = "Elasticsearch.Client.{$name}";
                if (null !== $analytics) {
                    $span->meta[Tag::ANALYTICS_KEY] = $analytics;
                }
                $span->service = NAME;
                $span->type = Type::ELASTICSEARCH;
                $span->resource = _dd_build_resource_name($name, isset($methodArgs[0]) ? $methodArgs[0] : []);
            }]);
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
    }]);
}
function _dd_integration_elasticsearch_simple()
{
    $methods = [['Elasticsearch\\Serializers\\ArrayToJSONSerializer', 'serialize'], ['Elasticsearch\\Serializers\\ArrayToJSONSerializer', 'deserialize'], ['Elasticsearch\\Serializers\\EverythingToJSONSerializer', 'serialize'], ['Elasticsearch\\Serializers\\EverythingToJSONSerializer', 'deserialize'], ['Elasticsearch\\Serializers\\SmartSerializer', 'serialize'], ['Elasticsearch\\Serializers\\SmartSerializer', 'deserialize']];
    foreach ($methods as $method) {
        $class = $method[0];
        $name = $method[1];
        \dd_trace_method($class, $name, function (SpanData $span) use($class, $name) {
            $operationName = str_replace('\\', '.', "{$class}.{$name}");
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
    $namespaces = ['IndicesNamespace' => ['analyze', 'clearCache', 'close', 'create', 'delete', 'deleteAlias', 'deleteMapping', 'deleteTemplate', 'deleteWarmer', 'exists', 'existsAlias', 'existsTemplate', 'existsType', 'flush', 'getAlias', 'getAliases', 'getFieldMapping', 'getMapping', 'getSettings', 'getTemplate', 'getWarmer', 'open', 'optimize', 'putAlias', 'putMapping', 'putSettings', 'putTemplate', 'putWarmer', 'recovery', 'refresh', 'segments', 'snapshotIndex', 'stats', 'status', 'updateAliases', 'validateQuery'], 'CatNamespace' => ['aliases', 'allocation', 'count', 'fielddata', 'health', 'help', 'indices', 'master', 'nodes', 'pendingTasks', 'recovery', 'shards', 'threadPool'], 'SnapshotNamespace' => ['create', 'createRepository', 'delete', 'deleteRepository', 'get', 'getRepository', 'restore', 'status'], 'ClusterNamespace' => ['getSettings', 'health', 'pendingTasks', 'putSettings', 'reroute', 'state', 'stats'], 'NodesNamespace' => ['hotThreads', 'info', 'shutdown', 'stats']];
    foreach ($namespaces as $namespace => $methods) {
        if (isset($DD_ES_GLOBAL[$namespace]) && $DD_ES_GLOBAL[$namespace] === true) {
            continue;
        }
        $DD_ES_GLOBAL[$namespace] = true;
        $class = 'Elasticsearch\\Namespaces\\' . $namespace;
        \dd_trace_method($class, '__construct', function () use($class, $namespace, $methods) {
            foreach ($methods as $name) {
                \dd_trace_method($class, $name, function (SpanData $span, $args) use($namespace, $name) {
                    $params = [];
                    if (isset($args[0])) {
                        list($params) = $args;
                    }
                    $span->name = "Elasticsearch.{$namespace}.{$name}";
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
    \dd_trace_method('Elasticsearch\\Endpoints\\AbstractEndpoint', 'performRequest', function (SpanData $span) {
        $span->name = "Elasticsearch.Endpoint.performRequest";
        $span->resource = 'performRequest';
        $span->service = NAME;
        $span->type = Type::ELASTICSEARCH;
        $span->meta[Tag::ELASTICSEARCH_URL] = $this->getURI();
        $span->meta[Tag::ELASTICSEARCH_METHOD] = $this->getMethod();
        if (is_array($this->params)) {
            $span->meta[Tag::ELASTICSEARCH_PARAMS] = json_encode($this->params);
        }
        if ($this->getMethod() === 'GET' && ($body = $this->getBody())) {
            $span->meta[Tag::ELASTICSEARCH_BODY] = json_encode($body);
        }
    });
}
function dd_integration_elasticsearch_load()
{
    _dd_integration_elasticsearch_client(\dd_config_analytics_is_enabled() && \dd_config_integration_analytics_is_enabled(NAME), \dd_config_integration_analytics_sample_rate(NAME));
    _dd_integration_elasticsearch_simple();
    _dd_integration_elasticsearch_namespace();
    _dd_integration_elasticsearch_endpoints();
    return SandboxedIntegration::LOADED;
}
}

