<?php

namespace DDTrace\Integrations\Swoole;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use function DDTrace\consume_distributed_tracing_headers;
use function DDTrace\extract_ip_from_headers;
use function DDTrace\Internal\handle_fork;
use function DDTrace\set_distributed_tracing_context;

class SwooleIntegration extends Integration
{
    const NAME = 'swoole';

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    public function instrumentRequestStart(callable $callback, SwooleIntegration $integration, Server $server)
    {
        $scheme = $server->ssl ? 'https://' : 'http://';

        \DDTrace\install_hook(
            $callback,
            function (HookData $hook) use ($integration, $server, $scheme) {
                $args = $hook->args;
                /** @var Request $request */
                $request = $args[0];

                $inferredProxyServicesEnabled = \dd_trace_env_config('DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED');
                $inferredSpan = null;
                if ($inferredProxyServicesEnabled) {
                    $inferredSpan = \DDTrace\start_inferred_span($request->header);
                    Logger::get()->debug("Inferred span? " . ($inferredSpan ? "yes" : "no"));
                }
                $hook->data['inferredSpan'] = $inferredSpan;

                $rootSpan = $hook->span($inferredSpan ?: new SpanStack());
                Logger::get()->debug("Created root span");
                $rootSpan->name = "web.request";
                $rootSpan->service = \ddtrace_config_app_name('swoole');
                $rootSpan->type = Type::WEB_SERVLET;
                $rootSpan->meta[Tag::COMPONENT] = SwooleIntegration::NAME;
                $rootSpan->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;
                $integration->addTraceAnalyticsIfEnabled($rootSpan);

                $headers = [];
                $allowedHeaders = \dd_trace_env_config('DD_TRACE_HEADER_TAGS');
                foreach ($request->header as $name => $value) {
                    $headers[strtolower($name)] = $value;
                    $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($name));
                    if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                        $rootSpan->meta["http.request.headers.$normalizedHeader"] = $value;
                    }
                }
                consume_distributed_tracing_headers(function ($key) use ($headers) {
                    return $headers[$key] ?? null;
                });

                if (\dd_trace_env_config("DD_TRACE_CLIENT_IP_ENABLED")) {
                    $res = extract_ip_from_headers($headers + ['REMOTE_ADDR' => $request->server['remote_addr']]);
                    $rootSpan->meta += $res;
                }

                if (isset($headers["user-agent"])) {
                    $rootSpan->meta["http.useragent"] = $headers["user-agent"];
                }

                $rawContent = $request->rawContent();
                if ($rawContent) {
                    // The raw content will always be populated if the request is a POST request, independent of the
                    // Content-Type header.
                    // However, it may not be json-decodable
                    $postFields = json_decode($rawContent, true);
                    if (is_null($postFields)) {
                        // Fallback to the post fields, which is an array
                        // This array is not always populated, depending on the Content-Type header
                        $postFields = $request->post;
                    }
                }
                if (!empty($postFields)) {
                    $postFields = Normalizer::sanitizePostFields($postFields);
                    foreach ($postFields as $key => $value) {
                        $rootSpan->meta["http.request.post.$key"] = $value;
                    }
                }

                $normalizedPath = Normalizer::uriNormalizeincomingPath(
                    $request->server['request_uri']
                    ?? $request->server['path_info']
                    ?? '/'
                );
                $rootSpan->resource = $request->server['request_method'] . ' ' . $normalizedPath;
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->server['request_method'];

                $host = $headers['host'] ?? ($request->server['remote_addr'] . ':' . $request->server['server_port']);
                $path = $request->server['request_uri'] ?? $request->server['path_info'] ?? '';
                $query = isset($request->server['query_string']) ? '?' . $request->server['query_string'] : '';
                $url = $scheme . $host . $path . $query;
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::uriNormalizeincomingPath($url);

                unset($rootSpan->meta['closure.declaration']);
            },
            function (HookData $hook) {
                $inferredSpan = $hook->data['inferredSpan'];
                if ($inferredSpan) {
                    Logger::get()->debug("Closing inferred span");
                    $autofinishConfig = ini_get('datadog.autofinish_spans');
                    ini_set('datadog.autofinish_spans', 'true');
                    dd_trace_close_all_spans_and_flush();
                    //dd_trace_synchronous_flush(1);
                    //set_distributed_tracing_context("0", "0");
                    ini_set('datadog.autofinish_spans', $autofinishConfig);
                }
            }
        );
    }

    public function instrumentWorkerStart(callable $callback, SwooleIntegration $integration, Server $server)
    {
        \DDTrace\install_hook(
            $callback,
            function (HookData $hook) use ($integration, $server) {
                handle_fork();
            }
        );
    }

    public function init(): int
    {
        if (version_compare(swoole_version(), '5.0.2', '<')) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        ini_set("datadog.trace.auto_flush_enabled", 1);
        ini_set("datadog.trace.generate_root_span", 0);

        \DDTrace\hook_method(
            'Swoole\Http\Server',
            '__construct',
            null,
            function ($server) use ($integration) {
                $server->on('workerstart', function () { });
            }
        );

        \DDTrace\hook_method(
            'Swoole\Http\Server',
            'on',
            null,
            function ($server, $scope, $args, $retval) use ($integration) {
                if ($retval === false) {
                    return; // Callback wasn't set
                }

                list($eventName, $callback) = $args;

                $eventName = strtolower($eventName);
                switch ($eventName) {
                    case 'request':
                        $integration->instrumentRequestStart($callback, $integration, $server);
                        break;
                    case 'workerstart':
                        $integration->instrumentWorkerStart($callback, $integration, $server);
                        break;
                }

            }
        );

        \DDTrace\hook_method(
            'Swoole\Http\Response',
            'end',
            function ($response, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    return;
                }

                // Note: The response's body can be retrieved here, from the args

                if (!$rootSpan->exception
                    && ((int)$rootSpan->meta[Tag::HTTP_STATUS_CODE]) >= 500
                    && $ex = \DDTrace\find_active_exception()
                ) {
                    do {
                        $rootSpan->exception = $ex;
                    } while ($rootSpan = $rootSpan->parent);
                }
            }
        );

        \DDTrace\hook_method(
            'Swoole\Http\Response',
            'header',
            function ($response, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null || \count($args) < 2) {
                    return;
                }

                /** @var string[] $args */
                list($key, $value) = $args;

                $allowedHeaders = \dd_trace_env_config("DD_TRACE_HEADER_TAGS");
                $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($key));
                if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                    $rootSpan->meta["http.response.headers.$normalizedHeader"] = $value;
                }
            }
        );

        \DDTrace\hook_method(
            'Swoole\Http\Response',
            'status',
            function ($response, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan && \count($args) > 0) {
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $args[0];
                }
            }
        );

        return Integration::LOADED;
    }
}
