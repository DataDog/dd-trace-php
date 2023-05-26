<?php

namespace DDTrace\Integrations\MonologIntegration;

use DDTrace\Integrations\Integration;
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
        \DDTrace\hook_method(
            'Monolog\Logger',
            '__construct',
            null,
            function ($This, $scope, $args) {
                $monologVersion = $This->API;

                switch ($monologVersion) {
                    case 1:
                        $callback = function ($record) {
                            return self::monologProcessorV1($record);
                        };
                        break;
                    case 2:
                        $callback = function ($record) {
                            return self::monologProcessorV2($record);
                        };
                        break;
                    case 3:
                        $callback = function ($record) {
                            return self::monologProcessorV3($record);
                        };
                        break;
                    default:
                        $callback = function ($record) {
                            return $record;
                        };
                        break;
                }

                $This->pushProcessor($callback);
            }
        );

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
        $record['dd'] = [
            'trace_id' => trace_id_128(),
            'span_id'  => dd_trace_peek_span_id()
        ];
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
