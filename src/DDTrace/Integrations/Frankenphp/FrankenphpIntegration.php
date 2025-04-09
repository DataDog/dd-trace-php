<?php

namespace DDTrace\Integrations\Frankenphp;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Type;
use function DDTrace\consume_distributed_tracing_headers;
use function DDTrace\UserRequest\notify_commit;
use function DDTrace\UserRequest\notify_start;
use function DDTrace\UserRequest\set_blocking_function;

class FrankenphpIntegration extends Integration
{
    const NAME = 'frankenphp';

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    public function init(): int
    {
        $integration = $this;

        ini_set("datadog.trace.auto_flush_enabled", 1);
        ini_set("datadog.trace.generate_root_span", 0);

        $is_hooked = new \WeakMap();
        \DDTrace\install_hook('frankenphp_handle_request', function (HookData $hook) use ($integration, $is_hooked) {
            $handler = $hook->args[0];
            if (isset($is_hooked[$handler])) {
                return;
            }
            $is_hooked[$handler] = true;

            $blockingException = null;

            \DDTrace\install_hook(
                $handler,
                function (HookData $hook) use ($integration, &$blockingException, &$rootSpan) {
                    $blockingException = null;
                    $rootSpan = $hook->span(new SpanStack());
                    $rootSpan->name = "web.request";
                    $rootSpan->service = \ddtrace_config_app_name('frankenphp');
                    $rootSpan->type = Type::WEB_SERVLET;
                    $rootSpan->meta[Tag::COMPONENT] = FrankenphpIntegration::NAME;
                    $rootSpan->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;
                    unset($rootSpan->meta["closure.declaration"]);
                    $integration->addTraceAnalyticsIfEnabled($rootSpan);

                    consume_distributed_tracing_headers(null);

                    if (empty($_POST) && \key_exists('HTTP_CONTENT_TYPE', $_SERVER) &&
                        \str_starts_with($_SERVER['HTTP_CONTENT_TYPE'], 'application/json')) {
                        $body = file_get_contents('php://input');
                        try {
                            $post = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $e) {
                            $post = array();
                        }
                    } else {
                        $post = $_POST;
                    }

                    $res = notify_start($rootSpan, array(
                        '_GET' => $_GET,
                        '_POST' => $post,
                        '_SERVER' => $_SERVER,
                        '_FILES' => $_FILES,
                        '_COOKIE' => $_COOKIE,
                    ), \fopen('php://input', 'r'));

                    if ($res) { // block
                        \http_response_code($res['status']);
                        foreach ($res['headers'] as $k => $v) {
                            \header("$k: $v", false);
                        }
                        if (\key_exists('body', $res)) {
                            echo $res['body'];
                        }
                        $blockingException = new FrankenphpAppSecException();
                        $hook->suppressCall();
                    } else {
                        set_blocking_function(
                            $rootSpan,
                            static function ($spec) use (&$blockingException) {
                                FrankenphpIntegration::commitBlockingResponse($spec);
                                $blockingException = new FrankenphpAppSecException();
                                throw $blockingException;
                            }
                        );
                    }
                },
                function (HookData $hookData) use (&$blockingException) {
                    $rootSpan = $hookData->span();

                    $res = notify_commit(
                        $rootSpan,
                        \http_response_code(),
                        FrankenphpIntegration::convertHeaders(\headers_list()),
                        null /* response body is available through special mechanisms */
                    );

                    // we did not block before and were now told to block
                    if (!$blockingException && $res) {
                        $blockingException = new FrankenphpAppSecException();
                        FrankenphpIntegration::commitBlockingResponse($res);
                    }

                    if ($blockingException && !$rootSpan->exception) {
                        $rootSpan->exception = $blockingException;
                    }
                },
                \DDTrace\HOOK_INSTANCE
            );
        });

        return Integration::LOADED;
    }

    public static function commitBlockingResponse(array $spec)
    {
        if (\headers_sent($filename, $line)) {
            // Response has been committed, headers have already been sent
            error_log(
                "Can't be blocked by AppSec, headers already sent in $filename on line $line",
                E_WARNING
            );
        } else {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            \http_response_code($spec['status']);
            \header_remove();
            foreach ($spec['headers'] as $k => $v) {
                \header("$k: $v");
            }
            if (\key_exists('body', $spec)) {
                echo $spec['body'];
            }
            \flush();
        }
    }

    // convert to the form name => array(values)
    public static function convertHeaders(array $headers) : array
    {
        $res = array();
        foreach ($headers as $header) {
            $parts = \explode(':', $header, 2);
            $name = \strtolower(\trim($parts[0]));
            $value = \trim($parts[1]);
            if (!\key_exists($name, $res)) {
                $res[$name] = array();
            }
            $res[$name][] = $value;
        }
        return $res;
    }
}

class FrankenphpAppSecException extends \Exception
{
    public function __construct()
    {
        parent::__construct('Request blocked by AppSec');
    }
}
