<?php

namespace DDTrace\Integrations\Logs;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;

use DDTrace\Util\ObjectKVStore;
use function DDTrace\trace_id_128;

class LogsIntegration extends Integration
{
    const NAME = 'logs';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    public function getPlaceholders(string $levelName, string $traceIdSubstitute = null, string $spanIdSubstitute = null): array
    {
        $placeholders = [
            '%dd.trace_id%' => 'dd.trace_id="' . ($traceIdSubstitute ?? trace_id_128()) . '"',
            '%dd.span_id%'  => 'dd.span_id="' . ($spanIdSubstitute ?? dd_trace_peek_span_id()) . '"',
        ];

        $appName = ddtrace_config_app_name();
        if ($appName) {
            $placeholders['%dd.service%'] = 'dd.service="' . $appName . '"';
        } else {
            $placeholders['%dd.service%'] = '';
        }

        $currentContext = \DDTrace\current_context();
        if ($currentContext['version']) {
            $placeholders['%dd.version%'] = 'dd.version="' . $currentContext['version'] . '"';
        } else {
            $placeholders['%dd.version%'] = '';
        }

        if ($currentContext['env']) {
            $placeholders['%dd.env%'] = 'dd.env="' . $currentContext['env'] . '"';
        } else {
            $placeholders['%dd.env%'] = '';
        }

        $placeholders['%level_name%'] = 'level_name="' . $levelName . '"';

        return $placeholders;
    }

    public function messageContainsPlaceholders(string $message, string $levelName): bool
    {
        $placeholders = $this->getPlaceholders($levelName);

        foreach ($placeholders as $placeholder => $value) {
            if (str_contains($message, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    public function appendTraceIdentifiersToMessage(string $message, string $levelName, string $traceIdSubstitute = null, string $spanIdSubstitute = null): string
    {
        $additional = "";

        $placeholders = $this->getPlaceholders($levelName, $traceIdSubstitute, $spanIdSubstitute);

        foreach ($placeholders as $placeholder => $value) {
            if (str_contains($message, $placeholder)) {
                $message = str_replace($placeholder, $value, $message);
            } elseif ($value) {
                $additional .= "$value ";
            }
        }

        if ($additional) {
            $additional = substr($additional, 0, -1);
            $message .= " [$additional]";
        }

        return $message;
    }

    public function replacePlaceholders(string $message, string $levelName, string $traceIdSubstitute = null, string $spanIdSubstitute = null): string
    {
        return strtr($message, $this->getPlaceholders($levelName, $traceIdSubstitute, $spanIdSubstitute));
    }

    public function addTraceIdentifiersToContext(array $context, string $levelName, string $traceIdSubstitute = null, string $spanIdSubstitute = null): array
    {
        $traceId = \DDTrace\trace_id();

        $context['dd'] = [
            'trace_id' => $traceIdSubstitute ?? trace_id_128(),
            'span_id' => $spanIdSubstitute ?? dd_trace_peek_span_id()
        ];

        $service = ddtrace_config_app_name();
        if ($service) {
            $context['dd']['service'] = $service;
        }

        $currentContext = \DDTrace\current_context();
        if ($currentContext['version']) {
            $context['dd']['version'] = $currentContext['version'];
        }

        if ($currentContext['env']) {
            $context['dd']['env'] = $currentContext['env'];
        }

        if (!isset($context['level_name'])) {
            $context['level_name'] = $levelName;
        }

        return $context;
    }

    public function getHookFn(string $levelName, int $messageIndex, int $contextIndex, LogsIntegration $integration): callable
    {
        return function (HookData $hook) use ($levelName, $messageIndex, $contextIndex, $integration) {
            /** @var string $message */
            $message = $hook->args[$messageIndex];
            /** @var array $context */
            $context = $hook->args[$contextIndex] ?? [];

            if (str_contains($message, '"trace_id"') || str_contains($message, 'dd.trace_id=')) {
                // Don't add the identifiers if they are already included in the message
                // The logger interface's methods will be called multiple times across abstract classes, trait, etc.
                // for a same message
                return;
            }

            $traceIdSubstitute = null;
            $spanIdSubstitute = null;
            if ($levelName === 'error' && isset($context['exception'])) {
                // Track the origin of an exception
                $exception = $context['exception'];
                $traceIdentifiers = ObjectKVStore::get($exception, 'exception_trace_identifiers');
                $traceIdSubstitute = isset($traceIdentifiers['trace_id']) ? $traceIdentifiers['trace_id'] : null;
                $spanIdSubstitute = isset($traceIdentifiers['span_id']) ? $traceIdentifiers['span_id'] : null;
            }

            if (dd_trace_env_config("DD_TRACE_APPEND_TRACE_IDS_TO_LOGS")) {
                // Always append the trace identifiers at the end of the message
                $message = $integration->appendTraceIdentifiersToMessage($message, $levelName, $traceIdSubstitute, $spanIdSubstitute);
            } elseif ($integration->messageContainsPlaceholders($message, $levelName)) {
                // Replace the placeholders with the actual values
                $message = $integration->replacePlaceholders($message, $levelName, $traceIdSubstitute, $spanIdSubstitute);
            } else {
                // Add the trace identifiers to the context
                // They may or may not be used by the formatter
                $context = $integration->addTraceIdentifiersToContext($context, $levelName, $traceIdSubstitute, $spanIdSubstitute);
            }

            $hook->args[$messageIndex] = $message;
            $hook->args[$contextIndex] = $context;

            $hook->overrideArguments($hook->args);
        };
    }

    public function init()
    {
        $integration = $this;

        $levelNames = [
            'debug',
            'info',
            'notice',
            'warning',
            'error',
            'critical',
            'alert',
            'emergency'
        ];

        foreach ($levelNames as $levelName) {
            \DDTrace\install_hook(
                "Psr\Log\LoggerInterface::$levelName",
                $this->getHookFn($levelName, 0, 1, $integration)
            );
        }

        \DDTrace\install_hook(
            "Psr\Log\LoggerInterface::log",
            $this->getHookFn('log', 1, 2, $integration)
        );

        foreach ($levelNames as $levelName) {
            \DDTrace\hook_method(
                'Monolog\Logger',
                $levelName,
                null,
                function ($This, $scope, $args) {
                    // Set all the handlers to use the JSON formatter
                    foreach ($This->getHandlers() as $handler) {
                        $handler->setFormatter(new \Monolog\Formatter\JsonFormatter());
                    }
                }
            );
        }

        return Integration::LOADED;
    }
}
