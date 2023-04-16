<?php

namespace DDTrace\Integration\LaravelQueue;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Type;
use Illuminate\Queue\Jobs\JobName;

class LaravelQueueIntegration extends Integration
{
    const NAME = 'queue';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        $integration = $this;

        \DDTrace\trace_method(
            'Illuminate\Queue\Worker',
            'process',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'laravel.queue.worker';

                $job = $args[1];

                if (!($job instanceof \Illuminate\Contracts\Queue\Job)) {
                    return;
                }
                $payload = $job->payload();
                $span->resource = JobName::resolve($job->getName(), $payload);
                $span->type = Type::MESSAGE_CONSUMER;
                /* ... */
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Contracts\Queue\Job',
            'fire',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'laravel.queue.fire';

                $job = $this;

                $payload = $job->payload();
                $span->resource = JobName::resolve($job->getName(), $payload);
                $span->type = Type::MESSAGE_CONSUMER;
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Contracts\Queue\Queue',
            '^^u'
        )

        return Integration::LOADED;
    }
}
