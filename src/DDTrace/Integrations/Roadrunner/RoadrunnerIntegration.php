<?php

namespace DDTrace\Integrations\Roadrunner;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use function DDTrace\UserRequest\notify_commit;
use function DDTrace\UserRequest\notify_start;
use function DDTrace\UserRequest\set_blocking_function;

/**
 * Roadrunner integration
 */
class RoadrunnerIntegration extends Integration
{
    const NAME = 'roadrunner';

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    public static function build_req_spec(\Spiral\RoadRunner\Http\Request $req) {
        $ret = array();

        // _SERVER
        $parsed_url = parse_url($req->uri);
        if (!$parsed_url) {
            return null;
        }

        $server = [
            'REMOTE_ADDR' => $req->remoteAddr,
            'SERVER_PROTOCOL' => $req->protocol,
            'REQUEST_METHOD' => $req->method,
            'REQUEST_URI' => ($parsed_url['path'] ?? '/') .
                (empty($parsed_url['query']) ? '' : ('?' . $parsed_url['query'])),
            'SERVER_NAME' => $parsed_url['host'],
            'SERVER_PORT' => $parsed_url['port'] ?? ($parsed_url['scheme'] === 'https' ? 443 : 80),
            'HTTP_HOST' => self::getHost($parsed_url),
        ];
        if ($parsed_url['scheme'] === 'https') {
            $server['HTTPS'] = 'on';
        }
        if (isset($parsed_url['query'])) {
            $_SERVER['QUERY_STRING'] = $parsed_url['query'];
        }

        foreach ($req->headers as $name => $values) {
            $collapsedValue = implode(', ', $values);
            $name = preg_replace("/[^A-Z\d]/", "_", strtoupper($name));
            // these two have special treatment. See RFC 3875
            if ($name != 'CONTENT_TYPE' && $name != 'CONTENT_LENGTH') {
                $name = "HTTP_$name";
            }
            $server[$name] = $collapsedValue;
        }

        $ret['_SERVER'] = $server;

        // _GET
        $ret['_GET'] = $req->query;

        // _POST
        if ($req->method == 'POST') {
            if ($req->parsed) {
                try {
                    $post = $req->getParsedBody();
                    $ret['_POST'] = $post;
                } catch (\JsonException $e) {
                }
            } else if (!empty($req->body) &&
                !empty($req->headers['Content-Type']) &&
                strpos($req->headers['Content-Type'][0], 'application/json') === 0) {
                // Roadrunner/V2/DistributedTracingTest.php seems to assume that json bodies should be
                // put in parsed form in POST as well
                try {
                    $post = json_decode($req->body, true);
                    $ret['_POST'] = $post;
                } catch (\JsonException $e) {
                }
            }
        }
        if (!isset($ret['_POST'])) {
            $ret['_POST'] = array();
        }

        // _COOKIE
        $ret['_COOKIE'] = $req->cookies;

        // _FILES
        $ret['_FILES'] = array_map(
            function ($upload) {
                return [
                    'name' => $upload['name'],
                    'type' => $upload['mime'],
                    'tmp_name' => $upload['tmpName'],
                    'error' => $upload['error'],
                    'size' => $upload['size'],
                ];
            },
            $req->uploads);

        return $ret;
    }

    private static function getHost(array $parsed_url) {
        $port = $parsed_url['port'] ?? 80;
        $scheme = $parsed_url['scheme'];
        if ($scheme === 'https') {
            if ($port === 443) {
                return $parsed_url['host'];
            }
        } else if ($port == 80) {
            return $parsed_url['host'];
        } else {
            return $parsed_url['host'] . ':' . $port;
        }
    }

    public function init(): int
    {
        $integration = $this;

        ini_set("datadog.trace.auto_flush_enabled", 1);
        ini_set("datadog.trace.generate_root_span", 0);

        $service = \ddtrace_config_app_name('roadrunner');

        $suppressResponse = null;
        $recCall = 0;

        \DDTrace\install_hook('Spiral\RoadRunner\Http\HttpWorker::waitRequest',
            function () use (&$activeSpan, &$suppressResponse) {
                if ($activeSpan) {
                    \DDTrace\close_spans_until($activeSpan);
                    \DDTrace\close_span();
                }
                $activeSpan = null;
                $suppressResponse = null;
            },
            function (HookData $hook) use (&$activeSpan, &$suppressResponse, $integration, $service, &$recCall) {
                /** @var ?\Spiral\RoadRunner\Http\Request $retval */
                $retval = $hook->returned;
                if (!$retval && !$hook->exception) {
                    return; // shutdown
                }
                if (!empty($hook->args)) {
                    return;
                }

                $activeSpan = \DDTrace\start_trace_span();

                $activeSpan->service = $service;
                $activeSpan->name = "web.request";
                $activeSpan->type = Type::WEB_SERVLET;
                $activeSpan->meta[Tag::COMPONENT] = RoadrunnerIntegration::NAME;
                $activeSpan->meta[Tag::SPAN_KIND] = 'server';
                $integration->addTraceAnalyticsIfEnabled($activeSpan);
                if ($hook->exception) {
                    $activeSpan->exception = $hook->exception;
                    \DDTrace\close_span();
                    $activeSpan = null;
                } else {
                    $headers = [];
                    foreach ($retval->headers as $headername => $header) {
                        $header = implode(", ", $header);
                        $headers[strtolower($headername)] = $header;
                    }
                    \DDTrace\consume_distributed_tracing_headers(function ($headername) use ($headers) {
                        return $headers[$headername] ?? null;
                    });

                    $res = notify_start($activeSpan, RoadrunnerIntegration::build_req_spec($retval), $retval->body);
                    if ($res) {
                        // block on start
                        RoadrunnerIntegration::ensure_headers_map_fmt($res['headers']);

                        $this->respond($res['status'], $res['body'] ?? '', $res['headers']);
                        \DDTrace\close_span();
                        $activeSpan = null;

                        if ($recCall++ > 128) {
                            // too many recursive calls. Exit so that the worker can be restarted
                            $hook->overrideReturnValue(null);
                            $this->getWorker()->stop();
                            return;
                        }
                        $hook->allowNestedHook();
                        $newRet = $this->waitRequest();
                        $hook->overrideReturnValue($newRet);
                        $recCall = 0;
                    } else {
                        $thiz = $this;
                        // to support block midrequest
                        set_blocking_function($activeSpan,
                            static function ($res) use (&$activeSpan, &$suppressResponse, $thiz) {
                                RoadrunnerIntegration::ensure_headers_map_fmt($res['headers']);
                                $thiz->respond($res['status'], $res['body'] ?? '', $res['headers']);
                                $suppressResponse = $activeSpan;
                                throw new \RuntimeException('Request blocked by AppSec');
                            });
                    }
                }
            });

        $respondBefore = function (HookData $hook) use (&$activeSpan, &$suppressResponse) {
            $hook->disableJitInlining();
            if (!$activeSpan || count($hook->args) < 3) {
                return;
            }

            if ($suppressResponse === $activeSpan) {
                // we're blocking midrequest and trying to second a second response.
                // (Maybe the application caught the RuntimeException thrown by the blocking function, and
                // now it's trying to respond with a 500)
                // Suppress this second response
                $hook->suppressCall();
                return;
            }

            $body = $hook->args[1];
            if (!is_string($body)) {
                // we're in respondStream and the body is a Generator
                // what we could do here would be to wrap the Generator until it is exhausted or
                // we read a certain amount of data from it, and only then call notify_commit, but...
                // 1. we don't really know what amount of data appsec is interested in
                // 2. we'd lose the ability to block because the response would have already been committed
                // While blocking is impossible, we could provide the chunks in a separate
                // user request hook or do away with abstraction and call an appsec function to
                // send arbitrary addresses.
                $body = null;
            }
            $blocking = notify_commit($activeSpan, $hook->args[0], $hook->args[2], $body);
            if ($blocking) {
                $hook->args[0] = $blocking['status'];
                $hook->args[1] = $blocking['body'];
                $hook->args[2] = RoadrunnerIntegration::ensure_headers_map_fmt($blocking['headers']);
                $hook->overrideArguments($hook->args);
            }
        };

        $respondAfter = function (HookData $hook) use (&$activeSpan, &$suppressResponse) {
            if (!$activeSpan || count($hook->args) < 3) {
                return;
            }

            /** @var int $status */
            $status = $hook->args[0];

            $activeSpan->meta[Tag::COMPONENT] = RoadrunnerIntegration::NAME;
            if ($hook->exception && empty($activeSpan->exception)) {
                $activeSpan->exception = $hook->exception;
            } elseif ($status >= 500 && $ex = \DDTrace\find_active_exception()) {
                $activeSpan->exception = $ex;
            }
        };

        \DDTrace\install_hook('Spiral\RoadRunner\Http\HttpWorker::respond', $respondBefore, $respondAfter);
        \DDTrace\install_hook('Spiral\RoadRunner\Http\HttpWorker::respondStream', $respondBefore, $respondAfter);

        return Integration::LOADED;
    }

    public static function ensure_headers_map_fmt(&$arr) {
        foreach ($arr as &$v) {
            if (!is_array($v)) {
                $v = [(string)$v];
            }
        }
        return $arr;
    }
}
