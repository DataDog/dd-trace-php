<?php

namespace DDTrace\Integrations\PeclAMQP;

use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;

class PeclAMQPIntegration extends Integration
{
    const NAME = 'peclamqp';
    const SYSTEM = 'rabbitmq';

    public function init(): int
    {
        \DDTrace\trace_method('AMQPConnection', '__construct', function (SpanData $span) {
            Logger::get()->debug('Tracing AMQPConnection::__construct');
            $span->name = "amqp.connection";
        });

        return Integration::LOADED;
    }
}
