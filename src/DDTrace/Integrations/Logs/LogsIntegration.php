<?php

namespace DDTrace\Integrations\Logs;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Util\ObjectKVStore;
use Psr\Log\NullLogger;

use function DDTrace\logs_correlation_trace_id;

class LogsIntegration extends Integration
{
    const NAME = 'logs';

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
        $traceIdSubstitute = null,
        $spanIdSubstitute = null
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

        return $placeholders;
    }

    public static function messageContainsPlaceholders(string $message): bool
    {
        $placeholders = LogsIntegration::getPlaceholders();

        foreach ($placeholders as $placeholder => $value) {
            if (strpos($message, $placeholder) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function appendTraceIdentifiersToMessage(
        string $message,
        $traceIdSubstitute = null,
        $spanIdSubstitute = null
    ): string {
        $placeholders = LogsIntegration::getPlaceholders($traceIdSubstitute, $spanIdSubstitute);
        LogsIntegration::replacePlaceholders($message, $placeholders);

        $additional = "";
        foreach ($placeholders as $placeholder => $value) {
            $key = substr($placeholder, 1, -1); // Placeholder without leading and trailing '%'
            if (strpos($message, $key) === false && $value) { // Append only if not already present
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
        $placeholders = null,
        $traceIdSubstitute = null,
        $spanIdSubstitute = null
    ): string {
        return strtr(
            $message,
            $placeholders ?: LogsIntegration::getPlaceholders($traceIdSubstitute, $spanIdSubstitute)
        );
    }

    public static function addTraceIdentifiersToContext(
        array $context,
        $traceIdSubstitute = null,
        $spanIdSubstitute = null
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

        return $context;
    }

    public static function getHookFn(
        string $levelName,
        int $messageIndex,
        int $contextIndex,
        $levelIndex = null
    ): callable {
        return static function (HookData $hook) use ($levelName, $messageIndex, $contextIndex, $levelIndex) {
            /** @var string $message */
            $message = $hook->args[$messageIndex];
            /** @var array $context */
            $context = $hook->args[$contextIndex] ?? [];

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
                // Append the trace identifiers at the END of the message, prioritizing placeholders, if any
                $message = LogsIntegration::appendTraceIdentifiersToMessage(
                    $message,
                    $traceIdSubstitute,
                    $spanIdSubstitute
                );
            } elseif (LogsIntegration::messageContainsPlaceholders($message)) {
                // Replace the placeholders, if any, with their actual values
                $message = LogsIntegration::replacePlaceholders(
                    $message,
                    null,
                    $traceIdSubstitute,
                    $spanIdSubstitute
                );
            } elseif (strpos($message, 'dd.trace_id=') === false) {
                // Add the trace identifiers to the context
                // They may or may not be used by the formatter
                $context = LogsIntegration::addTraceIdentifiersToContext(
                    $context,
                    $traceIdSubstitute,
                    $spanIdSubstitute
                );
            }

            $hook->args[$messageIndex] = $message;
            $hook->args[$contextIndex] = $context;

            $hook->overrideArguments($hook->args);
        };
    }

    public static function init(): int
    {
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
            $hook = \DDTrace\install_hook(
                "Psr\Log\LoggerInterface::$levelName",
                self::getHookFn($levelName, 0, 1)
            );
            \DDTrace\remove_hook($hook, NullLogger::class);
        }

        $hook = \DDTrace\install_hook(
            "Psr\Log\LoggerInterface::log",
            self::getHookFn('log', 1, 2, 0)
        );
        \DDTrace\remove_hook($hook, NullLogger::class);

        return Integration::LOADED;
    }
}
