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
use Illuminate\Queue\Jobs\JobName;
use function DDTrace\active_span;
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
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Processing Job');
                /** @var Job $job */
                $job = $args[1];

                $integration->setSpanAttributes($span, 'laravel.queue.process', $job, $exception);
                $span->meta['messaging.operation'] = 'process';

                //Logger::get()->debug('Span: ' . print_r($span, true));

                //$payload = $job->payload();
                //$integration->extractContext($payload);
            }
        );

        trace_method(
            'Illuminate\Contracts\Queue\Job',
            'fire',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Firing Job');
                // Distributed context was extracted in the Worker::process method
                $integration->setSpanAttributes($span, 'laravel.queue.fire', $this, $exception);
                $span->meta['messaging.operation'] = 'consume';
            }
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'fire',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Firing Job');
                // Distributed context was extracted in the Worker::process method
                $integration->setSpanAttributes($span, 'laravel.queue.fire', $this, $exception);
                $span->meta['messaging.operation'] = 'consume';
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
                    $span->name = 'laravel.action';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $integration->getName();
                    $span->resource = $class . '@' . $method;
                    $span->meta[Tag::COMPONENT] = LaravelQueueIntegration::NAME;
                });
            }
        );

        trace_method(
            'Illuminate\Queue\Jobs\Job',
            'resolve',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Resolving Job');
                // Distributed context was extracted in the Worker::process method
                $integration->setSpanAttributes($span, 'laravel.queue.resolve', $this, $exception);
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


        /*
        install_hook(
            'Illuminate\Queue\Queue::createPayload',
            null,
            function (HookData $hook) use ($integration) {
                // $hook->returned, a.k.a. the payload, should be a json encoded string
                // Decode it, add the distributed tracing headers, re-encode it, return this one instead
                $payload = $integration->injectContext(json_decode($hook->returned, true));
                $hook->overrideReturnValue(json_encode($payload));
                //$hook->returned = json_encode($payload);

                // TODO: If the above doesn't work out, use a function using '&' to modify the actual value directly instead of re-assigning

                // TODO: Exception Handling ?
            }
        );
        */



        trace_method(
            'Illuminate\Contracts\Queue\Queue',
            'push',
            function (SpanData $span, $args, $retval, $exception) use ($integration) {
                Logger::get()->debug('Pushing Job');
                $integration->setSpanAttributes($span, 'laravel.queue.push', $args[0], $exception, $args[2] ?? null);
                $span->meta['messaging.operation'] = 'produce';
            }
        );

        // TODO: Handle chains and batches ?

        return Integration::LOADED;
    }

    public function setSpanAttributes(SpanData $span, string $name, $job = null, $exception = null, $queue = null)
    {
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
        } elseif (is_object($job)) {
            Logger::get()->debug('Job is an object');
            $jobName = method_exists($job, 'displayName')
                ? $job->displayName() : get_class($job);
        } else { // string
            Logger::get()->debug('Job is a string');
            $jobName = $job;
        }
        $span->resource = $queue ? "$jobName -> $queue" : "$jobName";

        if ($exception) {
            $this->setError($span, $exception);
        }
    }

    public function getMetadataFromJob(Job $job)
    {
        $metadata = [
            'messaging.laravel.attempts' => $job->attempts(),
            'messaging.laravel.connection' => $job->getConnectionName(),
            'messaging.laravel.failed' => $job->hasFailed(),
            'messaging.laravel.deleted' => $job->isDeleted(),
            'messaging.laravel.id' => $job->getJobId(),
            'messaging.laravel.max_tries' => $job->maxTries(),
            'messaging.laravel.queue' => $job->getQueue(),
            'messaging.laravel.retry_until' => $job->retryUntil(),
            'messaging.laravel.timeout' => $job->timeout(),
            'messaging.laravel.resolve_name' => $job->resolveName(),
            'messaging.system' => 'laravel',
        ];

        $metadata = array_filter($metadata, function ($value) {
            return $value !== null;
        });

        return $metadata;
    }

    public function injectContext(array $payload)
    {
        if (\ddtrace_config_distributed_tracing_enabled() === false) {
            return $payload;
        }

        $payload['dd_headers'] = \DDTrace\generate_distributed_tracing_headers();

        $activeSpan = active_span();
        if ($activeSpan) {
            $payload['dd_span_link'] = $activeSpan->getLink();
        }

        return $payload;
    }

    public function extractContext(array $payload)
    {
        if (isset($payload['dd_headers'])) {
            \DDTrace\consume_distributed_tracing_headers($payload['dd_headers']);
        }

        $activeSpan = active_span();
        if (isset($payload['dd_span_link']) && $activeSpan) {
            $activeSpan->links[] = $payload['dd_span_link'];
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
