<?php

namespace DDTrace\Integrations\Swoole;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use Swoole\Http\Response;
use Swoole\Http\Server;

class SwooleIntegration extends Integration
{
    const NAME = 'swoole';

    /** @var Server $server */
    public $server;

    public function getName()
    {
        return self::NAME;
    }

    public function instrumentRequestStart(callable $callback): void
    {
        \DDTrace\install_hook(
            $callback,
            function (HookData $hook) {
                $span = $hook->span();
                $span->name = "web.request";
            },
            function (HookData $hook) {
                $span = $hook->span();

                \DDTrace\close_span();
            }
        );
    }

    public function instrumentRequestEnd(Response $response): void
    {

    }

    public function init()
    {
        $integration = $this;

        ini_set("datadog.trace.auto_flush_enabled", 1);
        ini_set("datadog.trace.generate_root_span", 0);

        $serviceName = \ddtrace_config_app_name('swoole');

        \DDTrace\hook_method(
            'Swoole\Http\Server',
            'on',
            null,
            function ($server, $scope, $args, $retval) use ($integration) {
                if ($retval === false) {
                    return; // Callback wasn't set
                }

                list($eventName, $callback) = $args;

                if ($eventName === 'request') {
                    $integration->instrumentRequestStart($callback);
                }
            }
        );

        \DDTrace\hook_method(
            'Swoole\Http\Response',
            'end',
            null,
            function ($response, $scope, $args, $retval) use ($integration) {
                $integration->instrumentRequestEnd($response);
            }
        );

        return Integration::LOADED;
    }
}
