<?php

namespace DDTrace\Integrations\SymfonyMessenger;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Util\DDTraceStamp;
use DDTrace\Util\ObjectKVStore;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\remove_hook;
use function DDTrace\trace_method;

class SymfonyMessengerIntegration extends Integration
{
    const NAME = 'symfonymessenger';

    public function init(): int
    {
        $integration = $this;

        trace_method(
            'Symfony\Component\Messenger\MessageBusInterface',
            'dispatch',
            function (SpanData $span, array $args, $returnValue, $exception = null) use ($integration) {
                $span->name = 'symfony.messenger.produce';
                $span->resource = \get_class($args[0]->getMessage());
                $integration->setSpanAttributes($span, null, $args[0], $exception);
            }
        );

        // Attach current context to Envelope before sender sends it to remote queue
        install_hook(
            'Symfony\Component\Messenger\Transport\Sender\SenderInterface::send',
            function (HookData $hook) {
                /** @var \Symfony\Component\Messenger\Envelope $envelope */
                Logger::get()->debug('Hooking Symfony Messenger SenderInterface::send');
                $envelope = $hook->args[0];

                if (\ddtrace_config_distributed_tracing_enabled()) {
                    $ddTraceStamp = $envelope->last(DDTraceStamp::class);

                    // Add distributed tracing stamp only if not already on the envelope
                    if ($ddTraceStamp === null) {
                        $tracingHeaders = \DDTrace\generate_distributed_tracing_headers();
                        Logger::get()->debug('Injecting ' . json_encode($tracingHeaders));
                        $hook->overrideArguments([
                            $envelope->with(new DDTraceStamp($tracingHeaders))
                        ]);
                    }
                }
            }
        );

        trace_method(
            'Symfony\Component\Messenger\Worker',
            'handleMessage',
            [
                'prehook' => function (SpanData $span, array $args) use ($integration, &$newTrace) {
                    $span->name = "symfony.messenger.consume";
                    /** @var \Symfony\Component\Messenger\Envelope $envelope */
                    $envelope = $args[0];
                    /** @var string $transportName */
                    $transportName = $args[1];

                    $integration->setSpanAttributes(
                        $span,
                        $transportName,
                        $envelope,
                        null,
                        true
                    );

                    $ddTraceStamp = $envelope->last(DDTraceStamp::class);
                    if ($ddTraceStamp instanceof DDTraceStamp) {
                        $tracingHeaders = $ddTraceStamp->getHeaders();
                        Logger::get()->debug('Extracting ' . json_encode($tracingHeaders));
                        if (\dd_trace_env_config('DD_TRACE_SYMFONY_MESSENGER_DISTRIBUTED_TRACING')) {
                            $newTrace = \DDTrace\start_trace_span();
                            $integration->setSpanAttributes(
                                $newTrace,
                                $transportName,
                                $envelope,
                                null,
                                true
                            );

                            //\DDTrace\consume_distributed_tracing_headers($tracingHeaders);

                            $span->links[] = $newTrace->getLink();
                            $newTrace->links[] = $span->getLink();
                        } else {
                            $span->links[] = \DDTrace\SpanLink::fromHeaders($tracingHeaders);
                        }
                    }
                },
                'posthook' => function (SpanData $span, array $args, $returnValue, $exception = null) use ($integration, &$newTrace) {
                    /** @var \Symfony\Component\Messenger\Envelope $envelope */
                    $envelope = $args[0];
                    /** @var string $transportName */
                    $transportName = $args[1];

                    if ($exception !== null) {
                        // Used by Logs Correlation to track the origin of an exception
                        ObjectKVStore::put(
                            $exception,
                            'exception_trace_identifiers',
                            [
                                'trace_id' => \DDTrace\logs_correlation_trace_id(),
                                'span_id' => \dd_trace_peek_span_id()
                            ]
                        );
                    }

                    $activeSpan = \DDTrace\active_span();
                    if (dd_trace_env_config('DD_TRACE_SYMFONY_MESSENGER_DISTRIBUTED_TRACING')
                        && $activeSpan !== $span
                        && $activeSpan === $newTrace) {
                        $integration->setSpanAttributes(
                            $activeSpan,
                            $transportName,
                            $envelope,
                            $exception,
                            true
                        );

                        \DDTrace\close_span();

                        if (
                            dd_trace_env_config("DD_TRACE_REMOVE_ROOT_SPAN_SYMFONY_MESSENGER")
                            && dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
                        ) {
                            \DDTrace\set_distributed_tracing_context("0", "0");
                        }
                    }

                    $integration->setSpanAttributes(
                        $span,
                        $transportName,
                        $envelope,
                        $exception,
                        true
                    );
                },
                'recurse' => true,
            ]
        );

        if (dd_trace_env_config('DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES')) {
            trace_method(
                'Symfony\Component\Messenger\Middleware\MiddlewareInterface',
                'handle',
                function (SpanData $span, array $args, $returnValue, $exception = null) use ($integration) {
                    $span->name = 'symfony.messenger.middleware';
                    $span->resource = \get_class($this);
                    $integration->setSpanAttributes(
                        $span,
                        null,
                        $args[0],
                        $exception
                    );
                }
            );

            // Since Symfony 6.2
            trace_method(
                'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
                'handle',
                function (SpanData $span, array $args, $returnValue, $exception = null) use ($integration) {
                    $span->name = 'symfony.messenger.middleware';
                    $span->resource = \get_class($this);
                    $integration->setSpanAttributes(
                        $span,
                        null,
                        null,
                        $exception
                    );

                }
            );

            hook_method(
                'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
                'callHandler',
                function ($This, $scope, $args) use ($integration) {
                    $handler = $args[0];
                    $class = \get_class($handler);
                    Logger::get()->debug("Installing hook for $class");
                    install_hook($handler, function (HookData $hook) use ($integration, $class) {
                        $span = $hook->span();
                        $span->name = 'symfony.messenger.handler';
                        $span->resource = get_class($this);
                        remove_hook($hook->id);
                    });
                }
            );
        }

        return Integration::LOADED;
    }

    public function setSpanAttributes(
        SpanData $span,
        $transportName = null,
        $envelope = null,
        $throwable = null,
        $useMessageAsResource = null
    ) {
        // Set defaults
        $operation = 'produce';
        $messageName = \is_object($envelope) ? \get_class($envelope) : null;
        $resource = null;

        if ($envelope instanceof Envelope) {
            $consumedByWorkerStamp = $envelope->last(ConsumedByWorkerStamp::class);
            $receivedStamp = $envelope->last(ReceivedStamp::class);
            $handledStamp = $envelope->last(HandledStamp::class);

            $messageName = \get_class($envelope->getMessage());
            $transportName = $receivedStamp ? $receivedStamp->getTransportName() : $transportName;

            if ($consumedByWorkerStamp || $receivedStamp) {
                $operation = 'receive';
            }

            if ($handledStamp) {
                $resource = $handledStamp->getHandlerName();
            }

            $span->meta = \array_merge($span->meta, $this->resolveMetadataFromEnvelope($envelope));
        }

        $span->resource = $resource;
        $span->service = \ddtrace_config_app_name('symfony');
        $span->type = 'queue';
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = 'symfonymessenger';
        $span->meta[Tag::MQ_OPERATION] = $operation;

        if (($useMessageAsResource ?? false) && $resource === null) {
            $span->resource = $transportName !== null && $transportName !== ''
                ? \sprintf('%s -> %s', $messageName, $transportName)
                : $messageName;
        }

        if ($throwable instanceof \Throwable) {
            $span->exception = $throwable;
        }
    }

    public function resolveMetadataFromEnvelope(Envelope $envelope): array
    {
        $busStamp = $envelope->last(BusNameStamp::class);
        $delayStamp = $envelope->last(DelayStamp::class);
        $handledStamp = $envelope->last(HandledStamp::class);
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        $transportMessageIdStamp = $envelope->last(TransportMessageIdStamp::class);

        $messageName = \get_class($envelope->getMessage());
        $transportName = $receivedStamp ? $receivedStamp->getTransportName() : null;
        $transportMessageId = $transportMessageIdStamp ? $transportMessageIdStamp->getId() : null;

        // AWS SQS
        if (\class_exists(AmazonSqsReceivedStamp::class)) {
            $amazonSqsReceivedStamp = $envelope->last(AmazonSqsReceivedStamp::class);
            $transportMessageId = $amazonSqsReceivedStamp ? $amazonSqsReceivedStamp->getId() : null;
        }

        $stamps = [];
        foreach ($envelope->all() as $stampFqcn => $instances) {
            $stamps[$stampFqcn] = \count($instances);
        }

        $metadata = [
            'messaging.symfony.bus' => $busStamp ? $busStamp->getBusName() : null,
            'messaging.symfony.name' => $messageName,
            'messaging.symfony.transport' => $transportName,
            'messaging.symfony.handler' => $handledStamp ? $handledStamp->getHandlerName() : null,
            'messaging.symfony.delay' => $delayStamp ? $delayStamp->getDelay() : null,
            'messaging.symfony.retry_count' => $redeliveryStamp ? $redeliveryStamp->getRetryCount() : null,
            'messaging.symfony.redelivered_at' => $redeliveryStamp ? $redeliveryStamp->getRedeliveredAt()->format('Y-m-d\TH:i:sP') : null,
            'messaging.symfony.stamps' => $stamps,
            Tag::MQ_DESTINATION => $transportName,
            Tag::MQ_SYSTEM => 'symfony',
            Tag::MQ_DESTINATION_KIND => 'queue',
            Tag::MQ_MESSAGE_ID => $transportMessageId,
        ];

        return \array_filter($metadata, function ($value): bool {
            if (\is_array($value)) {
                return \count($value) > 0;
            }

            return $value !== null && $value !== '';
        });
    }
}
