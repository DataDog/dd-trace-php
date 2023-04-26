<?php

namespace DDTrace\Integrations\LaravelQueue;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\JobName;
use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\start_trace_span;
use function DDTrace\trace_method;
use function DDTrace\install_hook;
use function DDTrace\hook_method;

class LaravelQueueIntegration extends Integration
{
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
        $integration = $this;

        trace_method(
            'Illuminate\Queue\Worker',
            'process',
            [
                'prehook' => function (SpanData $span, $args) use ($integration, &$newTrace) {
                    /** @var Job $job */
                    $job = $args[1];

                    $integration->setSpanAttributes($span, 'laravel.queue.process', 'receive', $job);

                    // Create a new trace
                    $payload = $job->payload();
                    if (isset($payload['dd_headers'])) {
                        $newTrace = start_trace_span();

                        $integration->setSpanAttributes($newTrace, 'laravel.queue.process', 'receive', $job);

                        $integration->extractContext($payload);
                        $span->links[] = $newTrace->getLink();
                    }
                },
                'posthook' => function (SpanData $span, $args, $retval, $exception) use ($integration, &$newTrace) {
                    /** @var Job $job */
                    $job = $args[1];

                    $activeSpan = active_span(); // This is the span created in the prehook, if any
                    if ($activeSpan !== $span && $activeSpan == $newTrace) {
                        $integration->setSpanAttributes($activeSpan, 'laravel.queue.process', 'receive', $job, $exception);
                        close_span();
                    }

                    $integration->setSpanAttributes($span, 'laravel.queue.process', 'receive', $job, $exception);
                },
                'recurse' => true
            ]
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'fire',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes($span, 'laravel.queue.fire', 'process', $this, $exception);
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

                    if (isset($this->batchId)) { // Uses the Batchable trait
                        $span->meta['messaging.laravel.batch_id'] = $this->batchId ?? null;
                    }

                    if (isset($this->job)) {
                        $integration->setSpanAttributes(
                            $span,
                            'laravel.queue.action',
                            null,
                            $this->job,
                            null,
                            null,
                            $class . '@' . $method
                        );
                    }
                });
            }
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'resolve',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes($span, 'laravel.queue.resolve', 'process', $this, $exception);
            }
        );

        trace_method(
            'Illuminate\Queue\Queue',
            'enqueueUsing',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes($span, 'laravel.queue.enqueueUsing', null, $args[0], $exception, $args[2]);
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
            }
        );

        trace_method(
            'Illuminate\Contracts\Queue\Queue',
            'push',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.push',
                    'send',
                    $args[0],
                    $exception,
                        $args[2] ?? null,
                    get_class($this)
                );
            }
        );

        trace_method(
            'Illuminate\Bus\Batch',
            'add',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.batch.add',
                    null,
                    $exception,
                    null,
                    get_class($this)
                );
                $span->meta[Tag::MQ_OPERATION] = 'send';
                if ($retval) {
                    $span->meta['messaging.laravel.batch_id'] = $retval->id;
                }
            }
        );

        return Integration::LOADED;
    }

    public function setSpanAttributes(
        SpanData $span,
        string $name,
        $operation = null,
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

        if ($operation) {
            $span->meta[Tag::MQ_OPERATION] = $operation;
        }

        if ($job instanceof Job) {
            $jobName = $job->resolveName();
            $span->meta = array_merge(
                $span->meta,
                $this->getMetadataFromJob($job)
            );
            $queue = $queue ?? $job->getQueue();
        } elseif (is_object($job)) { // Most certainly a CallQueuedClosure
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
        } else {
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
            return $payload;
        }

        $payload['dd_headers'] = \DDTrace\generate_distributed_tracing_headers();

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
