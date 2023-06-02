<?php

namespace DDTrace\Integrations\Monolog;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\trace_id_128;

class MonologIntegration extends Integration
{
    // TODO: Maybe move all of this to some sort of 'Utils' to be used for other logging libraries as well, if needed
    const NAME = 'monolog';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
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
            install_hook(
                "Psr\Log\LoggerInterface::$level",
                function (HookData $hook) use ($level) {
                    //Logger::get()->debug("Setting context for Psr\Log\LoggerInterface::$level");
                    $traceId = trace_id_128();
                    $spanId = dd_trace_peek_span_id();

                    $hook->args[0] = $hook->args[0] . " [dd.trace_id=\"$traceId\" dd.span_id=\"$spanId\"]";

                    /*
                    $currentContext = \DDTrace\current_context();

                    $hook->args[1]['dd'] = [
                        'trace_id' => trace_id_128(),
                        'span_id' => dd_trace_peek_span_id(),
                        'service' => ddtrace_config_app_name()
                    ];

                    if ($currentContext['version']) {
                        $hook->args[1]['dd']['version'] = $currentContext['version'];
                    }

                    if ($currentContext['env']) {
                        $hook->args[1]['dd']['env'] = $currentContext['env'];
                    }
                    */

                    $hook->overrideArguments([$hook->args[0], $hook->args[1] ?? []]);
                }
            );
        }

        return Integration::LOADED;
    }
}
