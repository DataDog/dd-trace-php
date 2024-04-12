<?php

namespace DDTrace\Integrations\OpenSwoole;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use function DDTrace\consume_distributed_tracing_headers;
use function DDTrace\extract_ip_from_headers;

class OpenSwooleIntegration extends Integration
{
    const NAME = 'openswoole';

    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    public function instrumentRequestStart(callable $callback, OpenSwooleIntegration $integration, \OpenSwoole\Http\Server $server)
    {
        Logger::get()->debug('Instrumenting OpenSwoole request start');
        //$scheme = $server->ssl ? 'https://' : 'http://';
        $scheme = 'http://';

        $id = \DDTrace\install_hook(
            $callback,
            function (HookData $hook) use ($integration, $server, $scheme) {
                Logger::get()->debug('OpenSwoole hooking request start');
                $rootSpan = $hook->span(new SpanStack());
                $rootSpan->name = "web.request";
                $rootSpan->service = \ddtrace_config_app_name('openswoole');
                $rootSpan->type = Type::WEB_SERVLET;
                $rootSpan->meta[Tag::COMPONENT] = OpenSwooleIntegration::NAME;
                $rootSpan->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;
                $integration->addTraceAnalyticsIfEnabled($rootSpan);

                $args = $hook->args;
                /** @var \OpenSwoole\Http\Request $request */
                $request = $args[0];

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
            }
        );

        Logger::get()->debug("Hook installed: $id");

        Logger::get()->debug('OpenSwoole request start instrumented');
    }

    public function init()
    {
        //if (version_compare(\OpenSwoole\Util::getVersion(), '22.', '<')) {
        //    return Integration::NOT_LOADED;
        //}

        $integration = $this;
        Logger::get()->debug('Initializing OpenSwoole integration');

        ini_set("datadog.trace.auto_flush_enabled", 1);
        ini_set("datadog.trace.generate_root_span", 0);

        \DDTrace\hook_method(
            'OpenSwoole\Http\Server',
            'on',
            null,
            function ($server, $scope, $args, $retval) use ($integration) {
                if ($retval === false) {
                    Logger::get()->debug('OpenSwoole hook failed');
                    return; // Callback wasn't set
                }

                list($eventName, $callback) = $args;

                if ($eventName === 'request') {
                    Logger::get()->debug('OpenSwoole hooking request');
                    $integration->instrumentRequestStart($callback, $integration, $server);
                }
            }
        );

        \DDTrace\hook_method(
            'OpenSwoole\Http\Response',
            'end',
            function () use ($integration) {
                Logger::get()->debug('OpenSwoole hooking response end');
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    Logger::get()->debug('OpenSwoole root span not found');
                    return;
                }

                // Note: The response's body can be retrieved here, from the args

                if (!$rootSpan->exception
                    && ((int)$rootSpan->meta[Tag::HTTP_STATUS_CODE]) >= 500
                    && $ex = \DDTrace\find_active_exception()
                ) {
                    $rootSpan->exception = $ex;
                }
            }
        );

        \DDTrace\hook_method(
            'OpenSwoole\Http\Response',
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
            'OpenSwoole\Http\Response',
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
