<?php

namespace DDTrace\Integrations\LaravelQueue;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\Log\LoggingTrait;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\JobName;
use Illuminate\Queue\Jobs\RedisJob;
use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\close_spans_until;
use function DDTrace\start_span;
use function DDTrace\start_trace_span;
use function DDTrace\switch_stack;
use function DDTrace\trace_method;
use function DDTrace\install_hook;
use function DDTrace\hook_method;

class LaravelQueueIntegration extends Integration
{
    use LoggingTrait;

    const NAME = 'laravelqueue';

    /**
     * @var string The app name. Note that this value is used as a cache, you should use method getAppName().
     */
    private $appName;

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
        self::logDebug('Initializing LaravelQueueIntegration');
        $integration = $this;

        trace_method(
            'Illuminate\Queue\Worker',
            'process',
            [
                'prehook' => function (SpanData $span, $args) use ($integration, &$newTrace) {
                    /** @var Job $job */
                    $job = $args[1];
                    Logger::get()->debug('Processing Job ' . $job->getJobId());

                    $integration->setSpanAttributes($span, 'laravel.queue.process', $job);
                    $span->meta['messaging.operation'] = 'receive'; // TODO: Change to receive ?

                    $payload = $job->payload();
                    Logger::get()->debug('Current span id: ' . dd_trace_peek_span_id());
                    // Create a new trace
                    if (isset($payload['dd_headers'])) {
                        $newTrace = start_trace_span();
                        //switch_stack(\DDTrace\root_span());
                        Logger::get()->debug('New span id: ' . dd_trace_peek_span_id());
                        $integration->setSpanAttributes($newTrace, 'laravel.queue.process', $job);
                        $newTrace->meta['messaging.operation'] = 'receive'; // TODO: Change to receive ?
                        $integration->extractContext($payload);
                        Logger::get()->debug('Before Links: ' . json_encode($span->links));
                        $span->links[] = $newTrace->getLink();
                        Logger::get()->debug('After Links: ' . json_encode($span->links));
                    }
                },
                'posthook' => function (SpanData $span, $args, $retval, $exception) use ($integration, &$newTrace) {
                    /** @var Job $job */
                    $job = $args[1];

                    $activeSpan = active_span(); // This is the span created in the prehook, if any
                    Logger::get()->debug('Active span id: ' . dd_trace_peek_span_id());
                    if ($activeSpan !== $span && $activeSpan == $newTrace) {
                        $integration->setSpanAttributes($activeSpan, 'laravel.queue.process', $job, $exception);
                        Logger::get()->debug('Closing span id: ' . dd_trace_peek_span_id());

                        //close_spans_until($activeSpan); // With this one, it flushes the right things
                        //close_spans_until($span); // With this one, it flushes the right things
                        // Doing nothing also works, prolly because the above commands are doing nothing as well

                        // Calling close_span doesn't make it work, as it looks like the trace 'stops' after the first process
                        //switch_stack($span);
                        //switch_stack($activeSpan);
                        close_span();
                        //close_span(); // Closes the trace
                        //close_spans_until(\DDTrace\root_span());
                        //close_span();
                        //close_spans_until(null);

                        Logger::get()->debug('Newly active span id: ' . dd_trace_peek_span_id());
                    }

                    $integration->setSpanAttributes($span, 'laravel.queue.process', $job, $exception);
                },
                'recurse' => true
            ]
        );

        trace_method(
            'Illuminate\Contracts\Queue\Job',
            'fire',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Firing Job');
                // Distributed context was extracted in the Worker::process method
                $integration->setSpanAttributes($span, 'laravel.queue.fire', $this, $exception);
                $span->meta[Tag::MQ_OPERATION] = 'process';
            }
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'fire',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Firing Job');
                // Distributed context was extracted in the Worker::process method
                $integration->setSpanAttributes($span, 'laravel.queue.fire', $this, $exception);
                $span->meta[Tag::MQ_OPERATION] = 'process';
            }
        );


        hook_method(
            'Illuminate\Queue\Jobs\Job',
            'fire',
            function ($job, $scope, $args) use ($integration) {
                $payload = $job->payload();
                list($class, $method) = JobName::parse($payload['job']);

                if ($class == 'Illuminate\\Queue\\CallQueuedHandler') {
                    $class = $payload['data']['commandName'];
                    $method = 'handle';
                }

                trace_method($class, $method, function (SpanData $span) use ($integration, $class, $method) {
                    $span->name = 'laravel.queue.action';
                    $span->type = 'queue';
                    $span->service = $integration->getName();
                    $span->resource = $class . '@' . $method;
                    $span->meta[Tag::COMPONENT] = LaravelQueueIntegration::NAME;
                    if (isset($this->batchId)) {
                        Logger::get()->debug('Batch id: ' . $this->batchId);
                        $span->meta['messaging.laravel.batch_id'] = $this->batchId ?? null;
                    }

                    if (isset($this->job)) {
                        Logger::get()->debug('Job id: ' . $this->job->getJobId());
                        Logger::get()->debug('Job uuid: ' . $this->job->uuid());
                        //$span->meta['messaging.laravel.id'] = $this->job->getJobId();
                        //$span->meta['messaging.laravel.uuid'] = $this->job->uuid();
                        $integration->setSpanAttributes(
                            $span,
                            'laravel.queue.action',
                            $this->job,
                            null,
                            null,
                            $class . '@' . $method
                        );
                    }
                });

                /*
                trace_method($class, 'batch', function (SpanData $span) use ($integration, $class, $method) {
                    $span->name = 'laravel.batch.action';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $integration->getName();
                    $span->resource = $class . '@' . 'batch';
                    $span->meta[Tag::COMPONENT] = LaravelQueueIntegration::NAME;
                });
                */
            }
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'resolve',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Resolving Job');
                // Distributed context was extracted in the Worker::process method
                $integration->setSpanAttributes($span, 'laravel.queue.resolve', $this, $exception);
                $span->meta[Tag::MQ_OPERATION] = 'process';
            }
        );

        trace_method(
            'Illuminate\Queue\Queue',
            'enqueueUsing',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                // TODO: payload can be modified here, using overrideArguments (w/. install_hook, obviously)
                Logger::get()->debug('Enqueueing Job');
                // Distributed context was extracted in the Worker::process method
                $integration->setSpanAttributes($span, 'laravel.queue.enqueueUsing', $args[0], $exception, $args[2]);
            }
        );

        install_hook(
            'Illuminate\Queue\Queue::createPayload',
            null,
            function (HookData $hook) use ($integration) {
                // $hook->returned, a.k.a. the payload, should be a json encoded string
                // Decode it, add the distributed tracing headers, re-encode it, return this one instead
                $payload = $integration->injectContext(json_decode($hook->returned, true));
                $hook->overrideReturnValue(json_encode($payload));

                // TODO: Exception Handling ?
            }
        );

        trace_method(
            'Illuminate\Contracts\Queue\Queue',
            'push',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Pushing Job');
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.push',
                    $args[0], $exception,
                        $args[2] ?? null,
                    get_class($this)
                );
                $span->meta[Tag::MQ_OPERATION] = 'send';
            }
        );

        // TODO: Handle chains and batches ?

        // TODO: in Illuminate\Bus\PendingBatch::dispatch, retrieve the batch id and ?

        /*
        trace_method(
            'Illuminate\Contracts\Queue\Queue',
            'bulk',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Bulk Pushing Jobs');
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.bulk',
                    $args[0], $exception,
                    $args[2] ?? null,
                    get_class($this)
                );
                $span->meta[Tag::MQ_OPERATION] = 'send';
            }
        );
        */

        trace_method(
            'Illuminate\Bus\Batch',
            'add',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Adding Job to Batch');
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.batch.add',
                    null,
                    $exception,
                    null,
                    get_class($this)
                );
                $span->meta[Tag::MQ_OPERATION] = 'send';
                $span->meta['messaging.laravel.batch_id'] = $retval ? $retval->id : null;
            }
        );

        trace_method(
            'Illuminate\Contracts\Queue\Queue',
            'later',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Pushing Job');
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.later',
                    $args[0], $exception,
                    $args[2] ?? null,
                    get_class($this)
                );
                $span->meta[Tag::MQ_OPERATION] = 'send';
            }
        );

        return Integration::LOADED;
    }

    public function setSpanAttributes(
        SpanData $span,
        string $name,
        $job = null,
        $exception = null,
        $queue = null,
        $resourceSubstitute = null
    ) {
        $span->name = $name;
        $span->service = $this->getAppName();
        $span->type = 'queue';
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = LaravelQueueIntegration::NAME;

        if ($job instanceof Job) {
            //$payload = $job->payload();
            //$span->resource = JobName::resolve($job->getName(), $payload);
            Logger::get()->debug('Job is instance of Job');
            $jobName = $job->resolveName();
            $span->meta = array_merge(
                $span->meta,
                $this->getMetadataFromJob($job)
            );
            $queue = $queue ?? $job->getQueue();
        } elseif (is_object($job)) { // Most certainly a CallQueuedClosure
            Logger::get()->debug('Job is an object');
            $jobName = method_exists($job, 'displayName')  ? $job->displayName() : get_class($job);
            $connectionName = $job->connection ?? config('queue.default');
            $queue = $queue ?? ($job->queue ?? (config("queue.connections.$connectionName.queue") ?? 'default'));

            $span->meta['messaging.laravel.name'] = $jobName;
            $span->meta['messaging.laravel.queue'] = $queue;
            $span->meta['messaging.laravel.connection'] = $connectionName;
            $span->meta = array_merge(
                $span->meta,
                $this->getMetadataFromObject($job)
            );
        } else { // string
            // TODO: Do a test using 'redis' connection
            Logger::get()->debug('Job is a string');
            $jobName = $job;
        }
        if ($resourceSubstitute) {
            $span->resource = $resourceSubstitute;
        } else {
            $span->resource = $queue ? "$jobName -> $queue" : "$jobName";
        }

        if ($exception) {
            $this->setError($span, $exception);
        }
    }

    public function getMetadataFromJob(Job $job)
    {
        $metadata = [
            'messaging.laravel.attempts' => $job->attempts(),
            'messaging.laravel.connection' => $job->getConnectionName() ?? config('queue.default'),
            'messaging.laravel.uuid' => $job->uuid(),
            'messaging.laravel.id' => $job->getJobId(),
            'messaging.laravel.max_tries' => $job->maxTries(),
            'messaging.laravel.queue' => $job->getQueue(),
            'messaging.laravel.retry_until' => $job->retryUntil(),
            'messaging.laravel.timeout' => $job->timeout(),
            'messaging.laravel.name' => $job->resolveName(),
            'messaging.system' => 'laravel',
        ];

        $metadata = array_filter($metadata, function ($value) {
            return !empty($value);
        });

        return $metadata;
    }

    public function getMetadataFromObject(object $job)
    {
        $metadata = [
            'messaging.laravel.max_tries' => $job->tries ?? null,
            'messaging.laravel.retry_until' => $job->retryUntil ?? null,
            'messaging.laravel.attempts' => $job->attempts() ?? null,
            'messaging.laravel.timeout' => $job->timeout ?? null,
            'messaging.laravel.queue' => $job->queue ?? null,
            'messaging.laravel.batch_id' => $job->batchId ?? null,
            'messaging.system' => 'laravel',
        ];

        $metadata = array_filter($metadata, function ($value) {
            return !empty($value);
        });

        return $metadata;
    }

    public function injectContext(array $payload)
    {
        if (\ddtrace_config_distributed_tracing_enabled() === false) {
            Logger::get()->debug('Distributed Tracing is disabled');
            return $payload;
        }

        $payload['dd_headers'] = \DDTrace\generate_distributed_tracing_headers();
        Logger::get()->debug('headers : ' . json_encode($payload['dd_headers']));

        return $payload;
    }

    public function extractContext(array $payload)
    {
        if (isset($payload['dd_headers'])) {
            \DDTrace\consume_distributed_tracing_headers($payload['dd_headers']);
        }
    }

    public function getAppName()
    {
        if (null !== $this->appName) {
            return $this->appName;
        }

        $name = \ddtrace_config_app_name();
        if (empty($name) && is_callable('config')) {
            $name = config('app.name');
        }

        $this->appName = $name ?: 'laravel';
        return $this->appName;
    }
}
