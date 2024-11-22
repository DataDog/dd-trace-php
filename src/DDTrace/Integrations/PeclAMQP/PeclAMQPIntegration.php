<?php

namespace DDTrace\Integrations\PeclAMQP;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use function DDTrace\remove_hook;

class PeclAMQPIntegration extends Integration
{
    const NAME = 'amqp';
    const SYSTEM = 'rabbitmq';

    public function init(): int
    {
        \DDTrace\trace_method(
            'AMQPConnection',
            '__construct',
            function (SpanData $span, $args) {
                $span->name = 'amqp.init';
            }
        );

        \DDTrace\hook_method('AMQPQueue', 'consume', function ($This, $args) {
           $callback = $args[0];

           \DDTrace\install_hook(
               $callback,
               function (HookData $hookData) {
                    $span = $hookData->span();

                    //... add attr

                   remove_hook($hookData->id);
               }
           );

        });

        return Integration::LOADED;
    }
}
