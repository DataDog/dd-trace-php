<?php

namespace DDTrace\Integrations\Roadrunner;

use DDTrace\Tag;
use DDTrace\SpanData;
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

        \DDTrace\hook_method('Spiral\RoadRunner\Http\PSR7Worker', 'waitRequest', [
            'prehook' => function () use (&$activeSpan) {
                if ($activeSpan) {
                    \DDTrace\close_spans_until($activeSpan);
                    \DDTrace\close_span();
                }
            },
            'posthook' => function ($psr, $scope, $args, $retval, $exception) use (&$activeSpan, $integration) {
                if (!$retval && !$exception) {
                    return; // shutdown
                }

                /** @var \Psr\Http\Message\ServerRequestInterface $retval */
                $activeSpan = \DDTrace\start_trace_span();
                $activeSpan->service = \ddtrace_config_app_name('roadrunner');
                $activeSpan->name = "web.request";
                $activeSpan->type = Type::WEB_SERVLET;
                $activeSpan->meta[Tag::COMPONENT] = $this->getName();
                $activeSpan->meta[Tag::SPAN_KIND] = 'server';
                $integration->addTraceAnalyticsIfEnabled($activeSpan);
                if ($exception) {
                    $activeSpan->exception = $exception;
                    \DDTrace\close_span();
                    $activeSpan = null;
                } else {
                    \DDTrace\consume_distributed_tracing_headers(function ($headername) use ($retval) {
                        $headers = $retval->getHeader($headername);
                        return $headers ? implode(", ", $headers) : null;
                    });
                    if (($userAgent = $retval->getHeaderLine("user-agent")) != "") {
                        $activeSpan->meta["http.useragent"] = $userAgent;
                    }
                    $normalizedPath = Normalizer::uriNormalizeincomingPath($retval->getUri()->getPath());
                    $activeSpan->resource = $retval->getMethod() . " " . $normalizedPath;
                    $activeSpan->meta["http.method"] = $retval->getMethod();
                    $activeSpan->meta["http.url"] = Normalizer::urlSanitize((string)$retval->getUri());
                    $allowedHeaders = \dd_trace_env_config("DD_TRACE_HEADER_TAGS");
                    foreach ($retval->getHeaders() as $header => $headers) {
                        $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($header));
                        if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                            $activeSpan->meta["http.request.headers.$normalizedHeader"] = reset($headers);
                        }
                    }
                }
            }
        ]);

        \DDTrace\hook_method('Spiral\RoadRunner\Http\PSR7Worker', 'respond', [
            'posthook' => function ($psr, $scope, $args, $retval, $exception) use (&$activeSpan) {
                if ($activeSpan) {
                    /** @var \Psr\Http\Message\ResponseInterface $response */
                    $response = $args[0];
                    $activeSpan->meta["http.status_code"] = $response->getStatusCode();
                    $activeSpan->meta[Tag::COMPONENT] = $this->getName();
                    $allowedHeaders = \dd_trace_env_config("DD_TRACE_HEADER_TAGS");
                    foreach ($response->getHeaders() as $header => $headers) {
                        $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($header));
                        if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                            $activeSpan->meta["http.response.headers.$normalizedHeader"] = reset($headers);
                        }
                    }
                    if ($exception && empty($activeSpan->exception)) {
                        $activeSpan->exception = $exception;
                    } elseif ($response->getStatusCode() >= 500 && $ex = \DDTrace\find_active_exception()) {
                        $activeSpan->exception = $ex;
                    }
                }
            }
        ]);

        return Integration::LOADED;
    }
}
