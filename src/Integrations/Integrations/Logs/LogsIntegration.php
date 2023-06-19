<?php

namespace DDTrace\Integrations\Logs;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Util\ObjectKVStore;

use function DDTrace\logs_correlation_trace_id;

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

    public static function laminasLogLevelToString(int $logLevel, string $fallback): string
    {
        switch ($logLevel) {
            case 0:
                return 'emergency';
            case 1:
                return 'alert';
            case 2:
                return 'critical';
            case 3:
                return 'error';
            case 4:
                return 'warning';
            case 5:
                return 'notice';
            case 6:
                return 'info';
            case 7:
                return 'debug';
            default:
                return $fallback;
        }
    }

    public static function getPlaceholders(
        string $levelName,
        string $traceIdSubstitute = null,
        string $spanIdSubstitute = null
    ): array {
        $placeholders = [
            '%dd.trace_id%' => 'dd.trace_id="' . ($traceIdSubstitute ?? logs_correlation_trace_id()) . '"',
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

    public static function messageContainsPlaceholders(string $message, string $levelName): bool
    {
        $placeholders = LogsIntegration::getPlaceholders($levelName);

        foreach ($placeholders as $placeholder => $value) {
            if (str_contains($message, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    public static function appendTraceIdentifiersToMessage(
        string $message,
        string $levelName,
        string $traceIdSubstitute = null,
        string $spanIdSubstitute = null
    ): string {
        $additional = "";

        $placeholders = LogsIntegration::getPlaceholders($levelName, $traceIdSubstitute, $spanIdSubstitute);

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

    public static function replacePlaceholders(
        string $message,
        string $levelName,
        string $traceIdSubstitute = null,
        string $spanIdSubstitute = null
    ): string {
        return strtr($message, LogsIntegration::getPlaceholders($levelName, $traceIdSubstitute, $spanIdSubstitute));
    }

    public static function addTraceIdentifiersToContext(
        array $context,
        string $levelName,
        string $traceIdSubstitute = null,
        string $spanIdSubstitute = null
    ): array {
        if (!isset($context['dd.trace_id'])) {
            $context['dd.trace_id'] = $traceIdSubstitute ?? logs_correlation_trace_id();
        }

        if (!isset($context['dd.span_id'])) {
            $context['dd.span_id'] = $spanIdSubstitute ?? dd_trace_peek_span_id();
        }

        $service = ddtrace_config_app_name();
        if ($service && !isset($context['dd.service'])) {
            $context['dd.service'] = $service;
        }

        $currentContext = \DDTrace\current_context();
        if ($currentContext['version'] && !isset($context['dd.version'])) {
            $context['dd.version'] = $currentContext['version'];
        }

        if ($currentContext['env'] && !isset($context['dd.env'])) {
            $context['dd.env'] = $currentContext['env'];
        }

        if (!isset($context['level_name'])) {
            $context['level_name'] = $levelName;
        }

        return $context;
    }

    public static function getHookFn(
        string $levelName,
        int $messageIndex,
        int $contextIndex,
        $levelIndex = null
    ): callable {
        return function (HookData $hook) use ($levelName, $messageIndex, $contextIndex, $levelIndex) {
            /** @var string $message */
            $message = $hook->args[$messageIndex];
            /** @var array $context */
            $context = $hook->args[$contextIndex] ?? [];

            if (str_contains($message, '"trace_id"') || str_contains($message, 'dd.trace_id=')) {
                // Don't add the identifiers if they are seemingly already included in the message
                // The logger interface's methods will be called multiple times across abstract classes, trait, etc.
                // for a same message
                return;
            }

            if (!is_null($levelIndex)) { // So that the level name that is inserted is better than 'log'
                if (is_string($hook->args[$levelIndex])) {
                    $levelName = $hook->args[$levelIndex];
                } elseif (is_int($hook->args[$levelIndex])) {
                    $levelName = LogsIntegration::laminasLogLevelToString($hook->args[$levelIndex], $levelName);
                }
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
                $message = LogsIntegration::appendTraceIdentifiersToMessage(
                    $message,
                    $levelName,
                    $traceIdSubstitute,
                    $spanIdSubstitute
                );
            } elseif (LogsIntegration::messageContainsPlaceholders($message, $levelName)) {
                // Replace the placeholders with the actual values
                $message = LogsIntegration::replacePlaceholders(
                    $message,
                    $levelName,
                    $traceIdSubstitute,
                    $spanIdSubstitute
                );
            } else {
                // Add the trace identifiers to the context
                // They may or may not be used by the formatter
                $context = LogsIntegration::addTraceIdentifiersToContext(
                    $context,
                    $levelName,
                    $traceIdSubstitute,
                    $spanIdSubstitute
                );
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
                self::getHookFn($levelName, 0, 1)
            );
        }

        \DDTrace\install_hook(
            "Psr\Log\LoggerInterface::log",
            self::getHookFn('log', 1, 2, 0)
        );

        return Integration::LOADED;
    }
}
