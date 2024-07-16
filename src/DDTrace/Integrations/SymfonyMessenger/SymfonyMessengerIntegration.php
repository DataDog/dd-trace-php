<?php

namespace DDTrace\Integrations\SymfonyMessenger;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Util\ObjectKVStore;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
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

        if (!\class_exists(\Symfony\Component\Messenger\Event\WorkerStartedEvent::class)) {
            // Only exists in Symfony Messenger 4.4+
            return Integration::NOT_LOADED;
        }

        trace_method(
            'Symfony\Component\Messenger\MessageBusInterface',
            'dispatch',
            function (SpanData $span, array $args, $retval, $exception) use ($integration) {
                $integration->setSpanAttributes($span, 'symfony.messenger.dispatch', null, $retval ?? $args[0], null, null, true);
                if ($exception) {
                    // Worker::handleMessage() will catch the exception. We need to manually attach it to the root span.
                    \DDTrace\root_span()->exception = $exception;
                }
            }
        );

        // Attach current context to Envelope before sender sends it to remote queue
        install_hook(
            'Symfony\Component\Messenger\Transport\Sender\SenderInterface::send',
            function (HookData $hook) {
                /** @var \Symfony\Component\Messenger\Envelope $envelope */
                $envelope = $hook->args[0];

                if (\ddtrace_config_distributed_tracing_enabled()) {
                    $ddTraceStamp = $envelope->last(DDTraceStamp::class);

                    // Add distributed tracing stamp only if not already on the envelope
                    if ($ddTraceStamp === null) {
                        $tracingHeaders = \DDTrace\generate_distributed_tracing_headers();
                        $hook->overrideArguments([
                            $envelope->with(new DDTraceStamp($tracingHeaders))
                        ]);
                    }
                }
            }
        );

        trace_method(
           'Symfony\Component\Messenger\Transport\TransportInterface',
           'send',
           function (SpanData $span, array $args, $envelope) use ($integration) {
               $integration->setSpanAttributes($span, 'symfony.messenger.send', null, $envelope ?? $args[0], null, 'send', true);
           }
        );

        trace_method(
            'Symfony\Component\Messenger\Worker',
            'handleMessage',
            [
                'prehook' => function (SpanData $span, array $args) use ($integration) {
                    /** @var \Symfony\Component\Messenger\Envelope $envelope */
                    $envelope = $args[0];
                    /** @var string|ReceiverInterface $transportName */
                    $transportName = $args[1];
                    if (\is_object($transportName)) {
                        $transportName = \get_class($transportName);
                    }

                    $integration->setSpanAttributes(
                        $span,
                        'symfony.messenger.consume',
                        null,
                        $envelope,
                        $transportName,
                        'receive'
                    );

                    $ddTraceStamp = $envelope->last(DDTraceStamp::class);
                    if ($ddTraceStamp instanceof DDTraceStamp) {
                        $tracingHeaders = $ddTraceStamp->getHeaders();
                        if (\dd_trace_env_config('DD_TRACE_SYMFONY_MESSENGER_DISTRIBUTED_TRACING')) {
                            \DDTrace\consume_distributed_tracing_headers($tracingHeaders);
                        } else {
                            $span->links[] = \DDTrace\SpanLink::fromHeaders($tracingHeaders);
                        }
                    }
                },
                'posthook' => function (SpanData $span) use ($integration) {
                    if ($span->exception !== null) {
                        // Used by Logs Correlation to track the origin of an exception
                        ObjectKVStore::put(
                            $span->exception,
                            'exception_trace_identifiers',
                            [
                                'trace_id' => \DDTrace\logs_correlation_trace_id(),
                                'span_id' => \dd_trace_peek_span_id()
                            ]
                        );
                    }
                },
                'recurse' => true,
            ]
        );

        $callHandlerExists = \method_exists('Symfony\Component\Messenger\Middleware\HandleMessageMiddleware', 'callHandler');
        if ($callHandlerExists) {
            // Symfony Messenger 6.2+
            hook_method(
                'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
                'callHandler',
                function ($This, $scope, $args) use ($integration) {
                    $message = $args[1];
                    install_hook($args[0], function (HookData $hook) use ($integration, $message) {
                        $integration->setSpanAttributes($hook->span(), 'symfony.messenger.handle', \get_class($this), $message, false, 'process');
                        remove_hook($hook->id);
                    });
                }
            );
        } else {
            hook_method(
                'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
                '__construct',
                function ($This, $scope, $args) {
                    /** @var HandlersLocatorInterface $handlersLocator */
                    $handlersLocator = $args[0];
                    ObjectKVStore::put($This, 'handlersLocator', $handlersLocator);
                }
            );

            hook_method(
                'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
                'handle',
                function ($This, $scope, $args) use ($integration) {
                    $envelope = $args[0];
                    $handlersLocator = ObjectKVStore::get($This, 'handlersLocator');
                    $message = $envelope->getMessage();
                    foreach ($handlersLocator->getHandlers($envelope) as $handlerDescriptor) {
                        if ($integration->messageHasAlreadyBeenHandled($envelope, $handlerDescriptor)) {
                            continue;
                        }

                        $handler = $handlerDescriptor->getHandler();
                        install_hook($handler, function (HookData $hook) use ($integration, $message) {
                            $integration->setSpanAttributes($hook->span(), 'symfony.messenger.handle', \get_class($this), $message, false, 'process');
                            remove_hook($hook->id);
                        });
                    }
                }
            );
        }

        if (dd_trace_env_config('DD_TRACE_SYMFONY_MESSENGER_MIDDLEWARES')) {
            $handleFn = function (SpanData $span, array $args) use ($integration) {
                $integration->setSpanAttributes($span, 'symfony.messenger.middleware', \get_class($this), $args[0]);
            };

            trace_method(
                'Symfony\Component\Messenger\Middleware\MiddlewareInterface',
                'handle',
                [
                    'posthook' => $handleFn,
                    'recurse' => true
                ]
            );

            // Symfony Messenger 6.2+
            trace_method(
                'Symfony\Component\Messenger\Middleware\HandleMessageMiddleware',
                'handle',
                [
                    'posthook' => $handleFn,
                    'recurse' => true
                ]
            );
        }

        return Integration::LOADED;
    }

    public function messageHasAlreadyBeenHandled(Envelope $envelope, HandlerDescriptor $handlerDescriptor): bool
    {
        $some = array_filter($envelope
            ->all(HandledStamp::class), function (HandledStamp $stamp) use ($handlerDescriptor) {
            return $stamp->getHandlerName() === $handlerDescriptor->getName();
        });

        return \count($some) > 0;
    }

    public function setSpanAttributes(
        SpanData $span,
        string $name,
        $resource = null,
        $envelopeOrMessage = null,
        $transportName = null,
        $operation = null,
        bool $addStampsInformation = false
    ) {
        if ($envelopeOrMessage instanceof Envelope) {
            $this->resolveMetadataFromEnvelope($span, $envelopeOrMessage, $resource, $transportName, $operation, $addStampsInformation);
        } else {
            $this->tryResolveMetadataFromMessage($span, $envelopeOrMessage, $resource, $transportName, $operation);
        }

        $span->name = $name;
        $span->service = \ddtrace_config_app_name('symfony');
        $span->type = 'queue';
        $span->meta[Tag::MQ_SYSTEM] = 'symfony';
        $span->meta[Tag::MQ_DESTINATION_KIND] = 'queue';
        $span->meta[Tag::COMPONENT] = SymfonyMessengerIntegration::NAME;
    }

    public function resolveMetadataFromEnvelope(
        SpanData $span,
        Envelope $envelope,
        $resource = null,
        $transportName = null,
        $operation = null,
        bool $addStampsInformation = false
    ) {
        $busStamp = $envelope->last(BusNameStamp::class);
        $consumedByWorkerStamp = $envelope->last(ConsumedByWorkerStamp::class);
        $delayStamp = $envelope->last(DelayStamp::class);
        $handledStamp = $envelope->last(HandledStamp::class);
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        $sentStamp = $envelope->last(SentStamp::class);
        $transportMessageIdStamp = $envelope->last(TransportMessageIdStamp::class);

        $messageName = \get_class($envelope->getMessage());
        $transportName = $sentStamp
            ? $sentStamp->getSenderAlias()
            : ($receivedStamp ? $receivedStamp->getTransportName() : $transportName);
        $senderClass = $sentStamp ? $sentStamp->getSenderClass() : null;
        $transportMessageId = $transportMessageIdStamp ? $transportMessageIdStamp->getId() : null;

        // amazon-sqs-messenger doesn't add TransportMessageIdStamp to the envelope
        if (\class_exists(AmazonSqsReceivedStamp::class)
            && ($amazonSqsReceivedStamp = $envelope->last(AmazonSqsReceivedStamp::class))
        ) {
            $transportMessageId = $amazonSqsReceivedStamp->getId();
        }

        $stamps = [];
        if ($addStampsInformation) {
            foreach ($envelope->all() as $stampFqcn => $instances) {
                $stamps[$stampFqcn] = \count($instances);
            }
        }

        if ($operation !== 'receive' && $operation !== 'send' && ($consumedByWorkerStamp || $receivedStamp)) {
            $operation = 'process';
        }

        $metadata = [
            'messaging.symfony.bus' => $busStamp ? $busStamp->getBusName() : null,
            'messaging.symfony.handler' => $handledStamp ? $handledStamp->getHandlerName() : null,
            'messaging.symfony.message' => $messageName,
            'messaging.symfony.redelivered_at' => $redeliveryStamp ? $redeliveryStamp->getRedeliveredAt()->format('Y-m-d\TH:i:sP') : null,
            'messaging.symfony.sender' => $senderClass,
            Tag::MQ_DESTINATION => $transportName,
            Tag::MQ_MESSAGE_ID => $transportMessageId,
            Tag::MQ_OPERATION => $operation,
            Tag::SPAN_KIND => $this->determineSpanKind($operation),
        ];

        $metrics = [
            'messaging.symfony.delay' => $delayStamp ? $delayStamp->getDelay() : null,
            'messaging.symfony.retry_count' => $redeliveryStamp ? $redeliveryStamp->getRetryCount() : null,
            'messaging.symfony.stamps' => $stamps,
        ];

        if (empty($resource)) {
            $span->resource = empty($transportName)
                ? $messageName
                : (($operation === 'receive' || $receivedStamp)
                    ? "$transportName -> $messageName"
                    : "$messageName -> $transportName"
                );
        } else {
            $span->resource = $resource;
        }
        $span->meta = \array_merge($span->meta, \array_filter($metadata));
        $span->metrics = \array_merge($span->metrics, \array_filter($metrics));
    }

    public function tryResolveMetadataFromMessage(SpanData $span, $message, $resource, $transportName, $operation) {
        if ($message) {
            $messageName = \get_class($message);
            $resource = $resource ?? $messageName;
            $span->meta['messaging.symfony.message'] = $messageName;
        }

        if ($resource) {
            $span->resource = $resource;
        }
        if ($transportName) {
            $span->meta[Tag::MQ_DESTINATION] = $transportName;
        }
        if ($operation) {
            $span->meta[Tag::MQ_OPERATION] = $operation;
        }

        $spanKind = $this->determineSpanKind($operation);
        if ($spanKind) {
            $span->meta[Tag::SPAN_KIND] = $spanKind;
        }
    }

    public function determineSpanKind($operation) {
        switch ($operation) {
            case 'receive':
                return Tag::SPAN_KIND_VALUE_CONSUMER;
            case 'send':
                return Tag::SPAN_KIND_VALUE_PRODUCER;
            default:
                return null; // Internal operation is implicit
        }
    }
}
