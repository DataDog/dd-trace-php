<?php

namespace DDTrace\Integrations\Roadrunner;

use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\Type;
use DDTrace\Util\Normalizer;

/**
 * Roadrunner integration
 */
class RoadrunnerIntegration extends Integration
{
    const NAME = 'roadrunner';

    /**
     * @return string The integration name.
     */
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

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        ini_set("datadog.trace.auto_flush_enabled", 1);
        ini_set("datadog.trace.generate_root_span", 0);

        \DDTrace\hook_method('Spiral\RoadRunner\Http\HttpWorker', 'waitRequest', [
            'prehook' => function () use (&$activeSpan) {
                if ($activeSpan) {
                    \DDTrace\close_spans_until($activeSpan);
                    \DDTrace\close_span();
                }
            },
            'posthook' => function ($worker, $scope, $args, $retval, $exception) use (&$activeSpan, $integration) {
                if (!$retval && !$exception) {
                    return; // shutdown
                }

                /** @var ?\Spiral\RoadRunner\Http\Request $retval */
                $activeSpan = \DDTrace\start_trace_span();
                $activeSpan->service = \ddtrace_config_app_name('roadrunner');
                $activeSpan->name = "web.request";
                $activeSpan->type = Type::WEB_SERVLET;
                $activeSpan->meta[Tag::COMPONENT] = RoadrunnerIntegration::NAME;
                $activeSpan->meta[Tag::SPAN_KIND] = 'server';
                $integration->addTraceAnalyticsIfEnabled($activeSpan);
                if ($exception) {
                    $activeSpan->exception = $exception;
                    \DDTrace\close_span();
                    $activeSpan = null;
                } else {
                    $headers = [];
                    $allowedHeaders = \dd_trace_env_config("DD_TRACE_HEADER_TAGS");
                    foreach ($retval->headers as $headername => $header) {
                        $header = implode(", ", $header);
                        $headers[strtolower($headername)] = $header;
                        $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($headername));
                        if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                            $activeSpan->meta["http.request.headers.$normalizedHeader"] = $header;
                        }
                    }
                    \DDTrace\consume_distributed_tracing_headers(function ($headername) use ($headers) {
                        return $headers[$headername] ?? null;
                    });

                    if (\dd_trace_env_config("DD_TRACE_CLIENT_IP_ENABLED")) {
                        $res = \DDTrace\extract_ip_from_headers($headers + ['REMOTE_ADDR' => $retval->remoteAddr]);
                        $activeSpan->meta += $res;
                    }

                    if (isset($headers["user-agent"])) {
                        $activeSpan->meta["http.useragent"] = $headers["user-agent"];
                    }
                    if (($urlParts = \parse_url($retval->uri)) && isset($urlParts["path"])) {
                        $normalizedPath = Normalizer::uriNormalizeincomingPath($urlParts["path"]);
                    } else {
                        $normalizedPath = "/";
                    }

                    if ($retval->body != "") {
                        // Try to json decode the body, if it fails, then don't do anything
                        // If it succeeds, then we can add the post fields to the span
                        $postFields = json_decode($retval->body, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $requestBody = Normalizer::sanitizePostFields($postFields);
                            foreach ($requestBody as $key => $value) {
                                $activeSpan->meta["http.request.post.$key"] = $value;
                            }
                        }
                    }

                    $activeSpan->resource = $retval->method . " " . $normalizedPath;
                    $activeSpan->meta["http.method"] = $retval->method;
                    $activeSpan->meta["http.url"] = Normalizer::urlSanitize($retval->uri);
                }
            }
        ]);

        \DDTrace\hook_method('Spiral\RoadRunner\Http\HttpWorker', 'respond', [
            'posthook' => function ($worker, $scope, $args, $retval, $exception) use (&$activeSpan) {
                if ($activeSpan) {
                    /** @var int $status */
                    $status = $args[0];
                    /** @var string[][] $headerList */
                    $headerList = $args[2];

                    $activeSpan->meta["http.status_code"] = $status;
                    $activeSpan->meta[Tag::COMPONENT] = RoadrunnerIntegration::NAME;
                    $allowedHeaders = \dd_trace_env_config("DD_TRACE_HEADER_TAGS");
                    foreach ($headerList as $header => $headers) {
                        $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($header));
                        if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                            $activeSpan->meta["http.response.headers.$normalizedHeader"] = implode(", ", $headers);
                        }
                    }
                    if ($exception && empty($activeSpan->exception)) {
                        $activeSpan->exception = $exception;
                    } elseif ($status >= 500 && $ex = \DDTrace\find_active_exception()) {
                        $activeSpan->exception = $ex;
                    }
                }
            }
        ]);

        return Integration::LOADED;
    }
}
