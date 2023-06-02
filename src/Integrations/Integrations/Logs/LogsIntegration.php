<?php

namespace DDTrace\Integrations\Logs;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;

use function DDTrace\hook_method;
use function DDTrace\install_hook;
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

    public function getPlaceholders(): array
    {
        return [
            '%dd.trace_id%' => 'dd.trace_id="' . trace_id_128() . '"',
            '%dd.span_id%'  => 'dd.span_id="' . dd_trace_peek_span_id() . '"',
            '%dd.service%'  => 'dd.service="' . ddtrace_config_app_name() . '"',
            '%dd.version%'  => 'dd.version="' . \DDTrace\current_context()['version'] . '"',
            '%dd.env%'      => 'dd.env="' . \DDTrace\current_context()['env'] . '"'
        ];
    }

    public function messageContainsPlaceholders(string $message): bool
    {
        $placeholders = $this->getPlaceholders();

        foreach ($placeholders as $placeholder => $value) {
            if (str_contains($message, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    public function appendTraceIdentifiersToMessage(string $message): string
    {
        if (str_contains($message, 'dd.trace_id')) {
            // Don't append the identifiers if they are already included in the message
            // The logger interface's methods will be called multiple times across abstract classes and all for a
            // same message
            return $message;
        }

        $message .= '[ ';

        $placeholders = $this->getPlaceholders();

        foreach ($placeholders as $placeholder => $value) {
            if ($value) {
                $key = str_replace('%', '', $placeholder);
                $message .= "$key=\"$value\" ";
            }
        }

        $message = substr($message, 0, -1);
        $message .= ']';

        return $message;
    }

    public function replacePlaceholders(string $message): string
    {
        return strtr($message, $this->getPlaceholders());
    }

    public function addTraceIdentifiersToContext(array $context): array
    {
        $context['dd'] = [
            'trace_id' => trace_id_128(),
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

        return $context;
    }

    public function init()
    {
        $hookFn = function(HookData $hook) {
            /** @var string $message */
            $message = $hook->args[0];
            /** @var array $context */
            $context = $hook->args[1] ?? [];

            if (dd_trace_env_config("DD_TRACE_APPEND_TRACE_IDS_TO_LOGS")) {
                // Always append the trace identifiers at the end of the message
                $message = $this->appendTraceIdentifiersToMessage($message);
            } elseif ($this->messageContainsPlaceholders($message)) {
                // Replace the placeholders with the actual values
                $message = $this->replacePlaceholders($message);
            } else {
                // Add the trace identifiers to the context
                // They may or may not be used by the formatter
                $context = $this->addTraceIdentifiersToContext($context);
            }

            $hook->overrideArguments([$message, $context]);
        };

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
            install_hook("Psr\Log\LoggerInterface::$level", $hookFn);
        }

        return Integration::LOADED;
    }
}
