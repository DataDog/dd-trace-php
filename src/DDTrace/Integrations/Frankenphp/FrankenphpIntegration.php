<?php

namespace DDTrace\Integrations\Frankenphp;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Type;
use function DDTrace\consume_distributed_tracing_headers;

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

            \DDTrace\install_hook(
                $handler,
                function (HookData $hook) use ($integration) {
                    $rootSpan = $hook->span(new SpanStack());
                    $rootSpan->name = "web.request";
                    $rootSpan->service = \ddtrace_config_app_name('frankenphp');
                    $rootSpan->type = Type::WEB_SERVLET;
                    $rootSpan->meta[Tag::COMPONENT] = FrankenphpIntegration::NAME;
                    $rootSpan->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;
                    unset($rootSpan->meta["closure.declaration"]);
                    $integration->addTraceAnalyticsIfEnabled($rootSpan);

                    consume_distributed_tracing_headers(null);
                },
                null,
                \DDTrace\HOOK_INSTANCE
            );
        });

        return Integration::LOADED;
    }
}
