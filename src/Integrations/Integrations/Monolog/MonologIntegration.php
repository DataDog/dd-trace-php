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
        /*
        install_hook(
            "Psr\Log\LoggerInterface::log",
            function (HookData $hook) {
                Logger::get()->debug('Setting context for log (LoggerInterface)');
                $hook->args[2]['dd'] = [
                    'trace_id' => trace_id_128(),
                    'span_id'  => dd_trace_peek_span_id()
                ];

                $hook->overrideArguments($hook->args);
            }
        );

        install_hook(
            "Psr\Log\AbstractLogger::log",
            function (HookData $hook) {
                Logger::get()->debug('Setting context for log (AbstractLogger)');
                $hook->args[2]['dd'] = [
                    'trace_id' => trace_id_128(),
                    'span_id'  => dd_trace_peek_span_id()
                ];

                $hook->overrideArguments($hook->args);
            }
        );
        */

        /*
        install_hook(
            "Illuminate\Log\LogManager::log",
            function (HookData $hook) {
                Logger::get()->debug('Setting context for log (LogManager)');
                $hook->args[2]['dd'] = [
                    'trace_id' => trace_id_128(),
                    'span_id'  => dd_trace_peek_span_id()
                ];

                $hook->overrideArguments($hook->args);
            }
        );
        */

        $levels = [
            'debug' => 100,
            'info' => 200,
            'notice' => 250,
            'warning' => 300,
            'error' => 400,
            'critical' => 500,
            'alert' => 550,
            'emergency' => 600
        ];

        foreach ($levels as $levelName => $level) {
            install_hook(
                "Psr\Log\LoggerInterface::$levelName",
                function (HookData $hook) use ($levelName, $level) {
                    Logger::get()->debug("Setting context for Psr\Log\LoggerInterface::$level");
                    $hook->args[1]['dd'] = [
                        'trace_id' => trace_id_128(),
                        'span_id' => dd_trace_peek_span_id(),
                        'service' => ddtrace_config_app_name()
                    ];
                    $hook->args[1]['level'] = $level; // TODO: That certainly shouldn't be here and handled in the pipeline/logs-backend (integrations-core)

                    $hook->overrideArguments($hook->args);
                }
            );
        }

        return Integration::LOADED;
    }

    public static function monologProcessorV1($record)
    {
        /*
        $record['message'] .= sprintf(
            ' [dd.trace_id=%s dd.span_id=%s]',
            trace_id_128(),
            dd_trace_peek_span_id()
        );
        return $record;
        */
        $record['dd'] = [
            'trace_id' => trace_id_128(),
            'span_id'  => dd_trace_peek_span_id()
        ];
        return $record;
    }

    public static function monologProcessorV2($record)
    {
        /*
        return $record->with(message: $record['message'] . sprintf(
                ' [dd.trace_id=%s dd.span_id=%s]',
                trace_id_128(),
                dd_trace_peek_span_id()
            ));
        */

        /*
        $record['dd'] = [
            'trace_id' => trace_id_128(),
            'span_id'  => dd_trace_peek_span_id()
        ];
        */

        //Logger::get()->debug("Setting extra...");
        /*
        $record['extra']['dd'] = [
            'trace_id' => trace_id_128(),
            'span_id'  => dd_trace_peek_span_id(),
            'service' => ddtrace_config_app_name()
        ];
        */
        //Logger::get()->debug("Set extra");
        return $record;
    }

    public static function monologProcessorV3($record)
    {
        $record->extra['dd'] = [
            'trace_id' => trace_id_128(),
            'span_id'  => dd_trace_peek_span_id()
        ];
        return $record;
    }
}
