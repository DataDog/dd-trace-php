<?php

namespace DDTrace\Integrations\Logs;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;

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

    public function getPlaceholders(string $level): array
    {
        $placeholders = [
            '%dd.trace_id%' => 'dd.trace_id="' . \DDTrace\trace_id_128() . '"',
            '%dd.span_id%'  => 'dd.span_id="' . dd_trace_peek_span_id() . '"',
        ];

        $appName = ddtrace_config_app_name();
        if ($appName) {
            $placeholders['%dd.service%'] = 'dd.service="' . $appName . '"';
        }

        $currentContext = \DDTrace\current_context();
        if ($currentContext['version']) {
            $placeholders['%dd.version%'] = 'dd.version="' . $currentContext['version'] . '"';
        }

        if ($currentContext['env']) {
            $placeholders['%dd.env%'] = 'dd.env="' . $currentContext['env'] . '"';
        }

        $placeholders['%level%'] = 'level="' . $level . '"';

        return $placeholders;
    }

    public function messageContainsPlaceholders(string $message, string $level): bool
    {
        $placeholders = $this->getPlaceholders($level);

        foreach ($placeholders as $placeholder => $value) {
            if (str_contains($message, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    public function appendTraceIdentifiersToMessage(string $message, string $level): string
    {
        $additional = "";

        $placeholders = $this->getPlaceholders($level);

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

    public function replacePlaceholders(string $message, string $level): string
    {
        return strtr($message, $this->getPlaceholders($level));
    }

    public function addTraceIdentifiersToContext(array $context, string $level): array
    {
        $context['dd'] = [
            'trace_id' => \DDTrace\trace_id_128(),
            'span_id' => dd_trace_peek_span_id()
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

        if (!isset($context['level'])) {
            $context['level'] = $level;
        }

        return $context;
    }

    public function getHookFn(string $level, int $messageIndex, int $contextIndex, LogsIntegration $integration): callable
    {
        return function (HookData $hook) use ($level, $messageIndex, $contextIndex, $integration) {
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

            if (dd_trace_env_config("DD_TRACE_APPEND_TRACE_IDS_TO_LOGS")) {
                // Always append the trace identifiers at the end of the message
                $message = $integration->appendTraceIdentifiersToMessage($message, $level);
            } elseif ($integration->messageContainsPlaceholders($message, $level)) {
                // Replace the placeholders with the actual values
                $message = $integration->replacePlaceholders($message, $level);
            } else {
                // Add the trace identifiers to the context
                // They may or may not be used by the formatter
                $context = $integration->addTraceIdentifiersToContext($context, $level);
            }

            $hook->args[$messageIndex] = $message;
            $hook->args[$contextIndex] = $context;

            $hook->overrideArguments($hook->args);
        };
    }

    public function init()
    {
        $integration = $this;

        $levels = [
            'debug',
            'info',
            'notice',
            'warning',
            'error',
            'critical',
            'alert',
            'emergency'
        ];

        foreach ($levels as $level) {
            \DDTrace\install_hook(
                "Psr\Log\LoggerInterface::$level",
                $this->getHookFn($level, 0, 1, $integration)
            );
        }

        \DDTrace\install_hook(
            "Psr\Log\LoggerInterface::log",
            $this->getHookFn($level, 1, 2, $integration)
        );

        return Integration::LOADED;
    }
}
