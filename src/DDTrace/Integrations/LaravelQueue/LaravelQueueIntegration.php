<?php

namespace DDTrace\Integrations\LaravelQueue;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Propagator;
use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\Tag;
use DDTrace\Util\ObjectKVStore;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\JobName;

use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\logs_correlation_trace_id;
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
     * @var string The app name. Note that this value is used as a cache, you should use method getServiceName().
     */
    private static $serviceName;

    /**
     * {@inheritdoc}
     */
    public static function init(): int
    {
        \DDTrace\hook_method(
            'Illuminate\Queue\Worker',
            'kill',
            static function () {
                // span.c:ddtrace_close_all_open_spans() -> ensure the span is closed rather than dropped
                ini_set('datadog.autofinish_spans', '1');

                dd_trace_close_all_spans_and_flush();
                dd_trace_synchronous_flush(1);

                if (
                    dd_trace_env_config("DD_TRACE_REMOVE_ROOT_SPAN_LARAVEL_QUEUE")
                    && dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
                ) {
                    set_distributed_tracing_context("0", "0");
                }
            }
        );

        install_hook(
            'Illuminate\Queue\Worker::process',
            static function (HookData $hook) {
                $span = $hook->span();
                /** @var Job $job */
                $job = $hook->args[1];

                self::setSpanAttributes($span, 'laravel.queue.process', 'receive', $job);

                $payload = $job->payload();
                if (isset($payload['dd_headers'])) {
                    if (dd_trace_env_config('DD_TRACE_LARAVEL_QUEUE_DISTRIBUTED_TRACING')) {
                        $newTrace = start_trace_span();
                        self::setSpanAttributes($newTrace, 'laravel.queue.process', 'receive', $job);
                        self::extractContext($payload);
                        $span->links[] = $newTrace->getLink();
                        $newTrace->links[] = $span->getLink();
                        $hook->data = $newTrace;
                    } else {
                        $span->links[] = SpanLink::fromHeaders($payload['dd_headers']);
                    }
                }
            },
            static function (HookData $hook) {
                $span = $hook->span();
                /** @var Job $job */
                $job = $hook->args[1];

                if ($hook->exception) {
                    // Used by Logs Correlation to track the origin of an exception
                    ObjectKVStore::put(
                        $hook->exception,
                        'exception_trace_identifiers',
                        [
                            'trace_id' => logs_correlation_trace_id(),
                            'span_id' => dd_trace_peek_span_id()
                        ]
                    );
                }

                $activeSpan = active_span(); // This is the span created in the prehook, if any
                if (dd_trace_env_config('DD_TRACE_LARAVEL_QUEUE_DISTRIBUTED_TRACING')
                    && $activeSpan !== $span
                    && $activeSpan === $hook->data
                ) {
                    self::setSpanAttributes(
                        $activeSpan,
                        'laravel.queue.process',
                        'receive',
                        $job,
                        $hook->exception
                    );
                    close_span();

                    if (
                        dd_trace_env_config("DD_TRACE_REMOVE_ROOT_SPAN_LARAVEL_QUEUE")
                        && dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
                    ) {
                        set_distributed_tracing_context("0", "0");
                    }
                }

                self::setSpanAttributes($span, 'laravel.queue.process', 'receive', $job, $hook->exception);
            }
        );

        hook_method(
            'Illuminate\Queue\Worker',
            'maxAttemptsExceededException',
            null,
            static function ($worker, $scope, $args, $retval) {
                if (($rootSpan = \DDTrace\root_span()) !== null) {
                    $rootSpan->exception = $retval;
                }
            }
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'fire',
            [
                'prehook' => function (SpanData $span, $args, $retval) {
                    LaravelQueueIntegration::setSpanAttributes($span, 'laravel.queue.fire', 'process', $this);
                },
                'posthook' => static function (SpanData $span, $args, $retval, $exception) {
                    if ($exception) {
                        $span->exception = $exception;
                    }
                }
            ]
        );

        install_hook(
            'Illuminate\Queue\Jobs\Job::fire',
            function (HookData $fireHook) {
                /** @var \Illuminate\Queue\Jobs\Job $this */
                $payload = $this->payload();
                list($class, $method) = JobName::parse($payload['job']);

                if ($class == 'Illuminate\\Queue\\CallQueuedHandler') {
                    $class = $payload['data']['commandName'];
                    $method = 'handle';
                }

                $fireHook->data['id'] = install_hook(
                    "$class::$method",
                    function (HookData $hook) use ($class, $method, $fireHook) {
                        $span = $hook->span();
                        $span->name = 'laravel.queue.action';
                        $span->type = 'queue';
                        $span->service = LaravelQueueIntegration::getServiceName();
                        $span->resource = $class . '@' . $method;
                        $span->meta[Tag::COMPONENT] = LaravelQueueIntegration::NAME;

                        if (isset($this->batchId)) { // Uses the Batchable trait; Laravel 8
                            $span->meta[Tag::LARAVELQ_BATCH_ID] = $this->batchId ?? null;
                        }

                        if (isset($this->job)) {
                            LaravelQueueIntegration::setSpanAttributes(
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
            },
            static function (HookData $fireHook) {
                remove_hook($fireHook->data['id']);
            }
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'resolve',
            function (SpanData $span, $args, $retval, $exception) {
                LaravelQueueIntegration::setSpanAttributes($span, 'laravel.queue.resolve', 'process', $this, $exception);
            }
        );

        // Laravel 8
        trace_method(
            'Illuminate\Queue\Queue',
            'enqueueUsing',
            static function (SpanData $span, $args, $retval, $exception) {
                self::setSpanAttributes(
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
            static function (HookData $hook) {
                // $hook->returned, a.k.a. the payload, should be a json encoded string
                // Decode it, add the distributed tracing headers, re-encode it, return this one instead
                $payload = self::injectContext(json_decode($hook->returned, true));
                $hook->overrideReturnValue(json_encode($payload));
            }
        );

        trace_method(
            'Illuminate\Contracts\Queue\Queue',
            'push',
            function (SpanData $span, $args, $retval, $exception) {
                LaravelQueueIntegration::setSpanAttributes(
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
            function (SpanData $span, $args, $retval, $exception) {
                LaravelQueueIntegration::setSpanAttributes(
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
            function (SpanData $span, $args, $retval, $exception) {
                LaravelQueueIntegration::setSpanAttributes(
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

    public static function setSpanAttributes(
        SpanData $span,
        string $name,
        $operation = null,
        $job = null,
        $exception = null,
        $queue = null,
        $resourceSubstitute = null
    ) {
        $span->name = $name;
        $span->service = self::getServiceName();
        $span->type = 'queue';
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = self::NAME;

        if ($operation) {
            $span->meta[Tag::MQ_OPERATION] = $operation;
        }

        if ($job instanceof Job) {
            $jobName = $job->resolveName();
            $span->meta = array_merge(
                $span->meta,
                self::getMetadataFromJob($job)
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
                self::getMetadataFromObject($job)
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
            $span->exception = $exception;
        }
    }

    public static function getMetadataFromJob(Job $job)
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

        $metadata = array_filter($metadata, static function ($value) {
            return !empty($value);
        });

        return $metadata;
    }

    public static function getMetadataFromObject($job)
    {
        $metadata = [
            Tag::LARAVELQ_MAX_TRIES => $job->tries ?? null,
            Tag::LARAVELQ_ATTEMPTS => \method_exists($job, 'attempts') ? $job->attempts() : null,
            Tag::LARAVELQ_TIMEOUT => $job->timeout ?? null,
            Tag::LARAVELQ_BATCH_ID => $job->batchId ?? null, // Laravel 8
            Tag::MQ_SYSTEM => 'laravel',
            Tag::MQ_DESTINATION_KIND => 'queue',
        ];

        $metadata = array_filter($metadata, static function ($value) {
            return !empty($value);
        });

        return $metadata;
    }

    public static function injectContext(array $payload)
    {
        if (!\ddtrace_config_distributed_tracing_enabled()) {
            return $payload;
        }

        $payload['dd_headers'] = \DDTrace\generate_distributed_tracing_headers();

        return $payload;
    }

    public static function extractContext(array $payload)
    {
        if (isset($payload['dd_headers'])) {
            \DDTrace\consume_distributed_tracing_headers($payload['dd_headers']);
        }
    }

    public static function getServiceName()
    {
        if (!empty(self::$serviceName)) {
            return self::$serviceName;
        }
        self::$serviceName = \ddtrace_config_app_name();
        try {
            if (empty(self::$serviceName) && is_callable('config')) {
                self::$serviceName = config('app.name');
            }
        } catch (\Throwable $e) {
            return 'laravel';
        }
        return self::$serviceName ?: 'laravel';
    }
}
