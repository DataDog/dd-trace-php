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
use function DDTrace\remove_hook;
use function DDTrace\set_distributed_tracing_context;
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
                        $newTrace->links[] = $span->getLink();
                    }
                },
                'posthook' => function (SpanData $span, $args, $retval, $exception) use ($integration, &$newTrace) {
                    /** @var Job $job */
                    $job = $args[1];

                    $activeSpan = active_span(); // This is the span created in the prehook, if any
                    if ($activeSpan !== $span && $activeSpan == $newTrace) {
                        $integration->setSpanAttributes(
                            $activeSpan,
                            'laravel.queue.process',
                            'receive',
                            $job,
                            $exception
                        );
                        close_span();

                        if (
                            dd_trace_env_config("DD_TRACE_REMOVE_ROOT_SPAN_LARAVEL_QUEUE")
                            && dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
                        ) {
                            set_distributed_tracing_context("0", "0");
                        }
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

        if (PHP_MAJOR_VERSION > 5) {
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

                    install_hook(
                        "$class::$method",
                        function (HookData $hook) use ($integration, $class, $method) {
                            $span = $hook->span();
                            $span->name = 'laravel.queue.action';
                            $span->type = 'queue';
                            $span->service = $integration->getName();
                            $span->resource = $class . '@' . $method;
                            $span->meta[Tag::COMPONENT] = LaravelQueueIntegration::NAME;

                            if (isset($this->batchId)) { // Uses the Batchable trait; Laravel 8
                                $span->meta[Tag::LARAVELQ_BATCH_ID] = $this->batchId ?? null;
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

                            remove_hook($hook->id);
                        }
                    );
                }
            );
        }

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'resolve',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes($span, 'laravel.queue.resolve', 'process', $this, $exception);
            }
        );

        // Laravel 8
        trace_method(
            'Illuminate\Queue\Queue',
            'enqueueUsing',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.enqueueUsing',
                    null,
                    $args[0],
                    $exception,
                    $args[2]
                );
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
            'Illuminate\Contracts\Queue\Queue',
            'later',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.later',
                    'send',
                    $args[1],
                    $exception,
                    $args[3] ?? null,
                    get_class($this)
                );
            }
        );

        // Laravel 8
        trace_method(
            'Illuminate\Bus\Batch',
            'add',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes(
                    $span,
                    'laravel.queue.batch.add',
                    'send',
                    null,
                    $exception,
                    null,
                    get_class($this)
                );
                if ($retval) {
                    $span->meta[Tag::LARAVELQ_BATCH_ID] = $retval->id;
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
            $jobName = method_exists($job, 'displayName') ? $job->displayName() : get_class($job);
            $connectionName = $job->connection ?? config('queue.default');
            $queue = $queue ?? ($job->queue ?? (config("queue.connections.$connectionName.queue") ?? 'default'));

            $span->meta[Tag::LARAVELQ_NAME] = $jobName;
            $span->meta[Tag::LARAVELQ_CONNECTION] = $connectionName;
            $span->meta[Tag::MQ_DESTINATION] = $queue;
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
            Tag::LARAVELQ_ATTEMPTS => $job->attempts(),
            Tag::LARAVELQ_CONNECTION => $job->getConnectionName() ?? config('queue.default'),
            Tag::LARAVELQ_MAX_TRIES => $job->maxTries(),
            Tag::LARAVELQ_TIMEOUT => $job->timeout(),
            Tag::LARAVELQ_NAME => $job->resolveName(),
            Tag::MQ_SYSTEM => 'laravel',
            Tag::MQ_MESSAGE_ID => $job->getJobId(),
            Tag::MQ_DESTINATION => $job->getQueue(),
            Tag::MQ_DESTINATION_KIND => 'queue',
        ];

        $metadata = array_filter($metadata, function ($value) {
            return !empty($value);
        });

        return $metadata;
    }

    public function getMetadataFromObject($job)
    {
        $metadata = [
            Tag::LARAVELQ_MAX_TRIES => $job->tries ?? null,
            Tag::LARAVELQ_ATTEMPTS => $job->attempts() ?? null,
            Tag::LARAVELQ_TIMEOUT => $job->timeout ?? null,
            Tag::LARAVELQ_BATCH_ID => $job->batchId ?? null, // Laravel 8
            Tag::MQ_SYSTEM => 'laravel',
            Tag::MQ_DESTINATION_KIND => 'queue',
        ];

        $metadata = array_filter($metadata, function ($value) {
            return !empty($value);
        });

        return $metadata;
    }

    public function injectContext(array $payload)
    {
        if (!\ddtrace_config_distributed_tracing_enabled()) {
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
