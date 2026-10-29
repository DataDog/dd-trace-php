<?php

namespace DDTrace\Integrations\AMQP;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Propagator;
use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\Tag;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPDecimal;
use PhpAmqpLib\Wire\AMQPTable;

use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\hook_method;
use function DDTrace\start_trace_span;
use function DDTrace\trace_method;

class AMQPIntegration extends Integration
{
    const NAME = 'amqp';
    const SYSTEM = 'rabbitmq';

    // Header carrying the Data Streams Monitoring pathway context.
    const DSM_CONTEXT_HEADER = 'dd-pathway-ctx-base64';

    public static $protocolVersion = "";

    // Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
    public static function largeBaseConvert($numString, $fromBase, $toBase)
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
        $toString = substr($chars, 0, $toBase);

        $length = strlen($numString);
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $number[$i] = strpos($chars, $numString[$i]);
        }
        do {
            $divide = 0;
            $newLen = 0;
            for ($i = 0; $i < $length; $i++) {
                $divide = $divide * $fromBase + $number[$i];
                if ($divide >= $toBase) {
                    $number[$newLen++] = (int)($divide / $toBase);
                    $divide = $divide % $toBase;
                } elseif ($newLen > 0) {
                    $number[$newLen++] = 0;
                }
            }
            $length = $newLen;
            $result = $toString[$divide] . $result;
        } while ($newLen != 0);

        return $result;
    }

    /**
     * Add instrumentation to AMQP requests
     */
    public static function init(): int
    {
        hook_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            '__construct',
            static function($This) {
                self::$protocolVersion = $This::getProtocolVersion();
            }
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_deliver",
            [
                'prehook' => static function (SpanData $span, $args) use (&$newTrace) {
                    /** @var AMQPMessage $message */
                    $message = $args[1];
                    if (self::hasDistributedHeaders($message)) {
                        $newTrace = start_trace_span();
                        self::extractContext($message);
                        $span->links[] = $newTrace->getLink();
                        $newTrace->links[] = $span->getLink();
                    }
                },
                'posthook' => static function (SpanData $span, $args) use (&$newTrace) {
                    /** @var AMQPMessage $message */
                    $message = $args[1];

                    $exchangeDisplayName = self::formatExchangeName($message->getExchange());
                    $routingKeyDisplayName = self::formatRoutingKey($message->getRoutingKey());

                    self::setGenericTags(
                        $span,
                        'basic.deliver',
                        'consumer',
                        "$exchangeDisplayName -> $routingKeyDisplayName"
                    );
                    $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();
                    $span->meta[Tag::MQ_OPERATION] = 'receive';
                    $span->meta[Tag::MQ_CONSUMER_ID] = $message->getConsumerTag();
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;

                    self::setOptionalMessageTags($span, $message);

                    $activeSpan = active_span();
                    if ($activeSpan !== $span && $activeSpan == $newTrace) {
                        self::setGenericTags(
                            $newTrace,
                            'basic.deliver',
                            'consumer',
                            "$exchangeDisplayName -> $routingKeyDisplayName"
                        );
                        $newTrace->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();
                        $newTrace->meta[Tag::MQ_OPERATION] = 'receive';
                        $newTrace->meta[Tag::MQ_CONSUMER_ID] = $message->getConsumerTag();
                        $newTrace->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
                        $newTrace->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                        self::setOptionalMessageTags($newTrace, $message);

                        // Close the created root span in the prehook
                        close_span();
                    }
                }
            ]
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "basic_publish",
            [
                'prehook' => static function (SpanData $span, $args) {
                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    if (!is_null($message)) {
                        self::injectContext($message);
                    }
                },
                'posthook' => static function (SpanData $span, $args, $exception) {
                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    /** @var string $exchange */
                    $exchange = $args[1];
                    /** @var string $routing_key */
                    $routingKey = $args[2] ?? '';

                    $exchangeDisplayName = self::formatExchangeName($exchange);
                    $routingKeyDisplayName = self::formatRoutingKey($routingKey);

                    self::setGenericTags(
                        $span,
                        'basic.publish',
                        'producer',
                        "$exchangeDisplayName -> $routingKeyDisplayName",
                        $exception
                    );
                    $span->meta[Tag::MQ_OPERATION] = 'send';

                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;

                    if (!is_null($message)) {
                        $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->getBody());
                        self::setOptionalMessageTags($span, $message);
                    }
                }
            ]
        );

        // Data Streams Monitoring hooks run apart from the spans' hooks, which stop once the
        // tracer reaches its span limit. This one is installed after the span's, so that it runs
        // after injectContext() and counts the headers that adds in the payload size.
        \DDTrace\install_hook(
            'PhpAmqpLib\Channel\AMQPChannel::basic_publish',
            static function (HookData $hook) {
                $message = $hook->args[0] ?? null;
                if ($message instanceof AMQPMessage && \dd_trace_env_config('DD_DATA_STREAMS_ENABLED')) {
                    self::setDataStreamsProduceCheckpoint($message, $hook->args[1] ?? '', $hook->args[2] ?? '');
                }
            }
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "batch_basic_publish",
            [
                'prehook' => static function (SpanData $span, $args) {
                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    if (!is_null($message)) {
                        self::injectContext($message);
                    }
                },
                'posthook' => static function (SpanData $span, $args, $exception) {
                    /** @var AMQPMessage $message */
                    $message = $args[0];
                    /** @var string $exchange */
                    $exchange = $args[1];
                    /** @var string $routing_key */
                    $routingKey = $args[2] ?? '';

                    $exchangeDisplayName = self::formatExchangeName($exchange);
                    $routingKeyDisplayName = self::formatRoutingKey($routingKey);

                    self::setGenericTags(
                        $span,
                        'batch_basic.add',
                        'producer',
                        "$exchangeDisplayName -> $routingKeyDisplayName",
                        $exception
                    );

                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;

                    if (!is_null($message)) {
                        $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = strlen($message->getBody());
                        self::setOptionalMessageTags($span, $message);
                    }
                }
            ]
        );

        trace_method(
            "PhpAmqpLib\Channel\AMQPChannel",
            "publish_batch",
            static function (SpanData $span, $args, $exception) {
                self::setGenericTags(
                    $span,
                    'publish_batch',
                    'producer',
                    null,
                    $exception
                );
                $span->meta[Tag::MQ_OPERATION] = 'send';
            }
        );

        // A delivery names its consumer, but not the queue that consumer reads, and basic_deliver
        // only reads the consumer off the wire right before calling back. So the consume
        // checkpoint is set by wrapping the callback, which knows the queue it was registered for
        // and runs before any of the application's code for the message.
        \DDTrace\install_hook(
            'PhpAmqpLib\Channel\AMQPChannel::basic_consume',
            static function (HookData $hook) {
                $hook->span();
                if (!\dd_trace_env_config('DD_DATA_STREAMS_ENABLED')
                        || !isset($hook->args[6]) || !is_callable($hook->args[6])) {
                    return;
                }
                $queue = (string)($hook->args[0] ?? '');
                $callback = $hook->args[6];
                $hook->args[6] = static function ($message) use ($queue, $callback) {
                    // Each message gets its own pathway, so what the callback produces links to
                    // this message only, and nothing after the callback links to it. Not fiber
                    // safe: callbacks that interleave on fibers would pop each other's pathways.
                    // That's fine for now, as php-amqplib calls back synchronously, one message
                    // at a time, from wait().
                    \DDTrace\Internal\dsm_push_pathway();
                    try {
                        if ($message instanceof AMQPMessage) {
                            // Unlike in a hook, an exception here would reach the application and
                            // keep the message from its callback.
                            try {
                                self::setDataStreamsConsumeCheckpoint($message, $queue);
                            } catch (\Throwable $e) {
                            }
                        }
                        return call_user_func($callback, $message);
                    } finally {
                        \DDTrace\Internal\dsm_pop_pathway();
                    }
                };
                $hook->overrideArguments($hook->args);
            },
            static function (HookData $hook) {
                /** @var string $queue */
                $queue = $hook->args[0] ?? null;
                /** @var string $consumer_tag */
                $consumerTag = $hook->args[1] ?? null;

                $queueDisplayName = self::formatQueueName($queue);

                $span = $hook->span();
                self::setGenericTags(
                    $span,
                    'basic.consume',
                    'client',
                    $queueDisplayName,
                    $hook->exception
                );
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_CONSUMER_ID] = $hook->returned ?? $consumerTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'exchange_declare',
            static function (SpanData $span, $args, $retval, $exception) {
                /** @var string $exchange */
                $exchange = $args[0];

                $exchangeDisplayName = self::formatExchangeName($exchange);

                self::setGenericTags(
                    $span,
                    'exchange.declare',
                    'client',
                    $exchangeDisplayName,
                    $exception
                );
                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_declare',
            static function (SpanData $span, $args, $retval, $exception) {
                /** @var string $queue */
                $queue = $args[0];
                if (empty($queue) && is_array($retval)) {
                    list($queue, ,) = $retval;
                }

                $queueDisplayName = self::formatQueueName($queue);

                self::setGenericTags(
                    $span,
                    'queue.declare',
                    'client',
                    $queueDisplayName,
                    $exception
                );
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'queue_bind',
            static function (SpanData $span, $args, $retval, $exception) {

                /** @var string $queue */
                $queue = $args[0];
                /** @var string $exchange */
                $exchange = $args[1];
                /** @var string $routingKey */
                $routingKey = $args[2] ?? '';

                $queueDisplayName = self::formatQueueName($queue);
                $exchangeDisplayName = self::formatExchangeName($exchange);
                $routingKeyDisplayName = self::formatRoutingKey($routingKey);

                self::setGenericTags(
                    $span,
                    'queue.bind',
                    'client',
                    "$queueDisplayName $exchangeDisplayName -> $routingKeyDisplayName",
                    $exception
                );
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;

                $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;
                $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_consume_ok',
            static function (SpanData $span) {
                self::setGenericTags($span, 'basic.consume_ok', 'server');

                $span->meta[Tag::MQ_OPERATION] = 'process';
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_cancel',
            static function (SpanData $span, $args, $retval, $exception) {
                /** @var string $consumerTag */
                $consumerTag = $args[0];

                self::setGenericTags(
                    $span,
                    'basic.cancel',
                    'client',
                    $consumerTag,
                    $exception
                );
                $span->meta[Tag::MQ_CONSUMER_ID] = $consumerTag;
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_cancel_ok',
            static function (SpanData $span, $args, $retval, $exception) {
                self::setGenericTags($span, 'basic.cancel_ok', 'server', null, $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'connect',
            static function (SpanData $span, $args, $retval, $exception) {
                self::setGenericTags($span, 'connect', 'client', null, $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Connection\AbstractConnection',
            'reconnect',
            static function (SpanData $span, $args, $retval, $exception) {
                self::setGenericTags($span, 'reconnect', 'client', null, $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_ack',
            static function (SpanData $span, $args, $retval, $exception) {
                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                self::setGenericTags($span, 'basic.ack', 'process', $deliveryTag, $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_nack',
            static function (SpanData $span, $args, $retval, $exception) {
                /** @var int $deliveryTag */
                $deliveryTag = $args[0];

                self::setGenericTags($span, 'basic.nack', 'process', $deliveryTag, $exception);
            }
        );

        trace_method(
            'PhpAmqpLib\Channel\AMQPChannel',
            'basic_get',
            static function (SpanData $span, $args, $message, $exception) {
                /** @var string $queue */
                $queue = $args[0];

                $queueDisplayName = self::formatQueueName($queue);

                self::setGenericTags(
                    $span,
                    'basic.get',
                    'consumer',
                    $queueDisplayName,
                    $exception
                );
                $span->meta[Tag::MQ_OPERATION] = 'receive';
                $span->meta[Tag::MQ_DESTINATION] = $queueDisplayName;

                if (!is_null($message)) {
                    /** @var AMQPMessage $message */
                    $exchange = $message->getExchange();
                    $routingKey = $message->getRoutingKey();

                    $exchangeDisplayName = self::formatExchangeName($exchange);
                    $routingKeyDisplayName = self::formatRoutingKey($routingKey);

                    $span->meta[Tag::MQ_MESSAGE_PAYLOAD_SIZE] = $message->getBodySize();

                    $span->meta[Tag::RABBITMQ_ROUTING_KEY] = $routingKeyDisplayName;
                    $span->meta[Tag::RABBITMQ_EXCHANGE] = $exchangeDisplayName;

                    self::setOptionalMessageTags($span, $message);

                    // Create the span link to the emitting trace
                    if ($message->has('application_headers')) {
                        $headers = $message->get('application_headers')->getNativeData();
                        $traceId = $headers[Propagator::DEFAULT_TRACE_ID_HEADER] ?? null;
                        $parentId = $headers[Propagator::DEFAULT_PARENT_ID_HEADER] ?? null;

                        if ($traceId && $parentId) {
                            // Only convert to hex if it's not already in hex
                            if (preg_match('/^[a-fA-F0-9]{32}$/', $traceId)) {
                                $traceId = strtolower($traceId);
                            } else {
                                $traceId = self::largeBaseConvert($traceId, 10, 16);
                                $traceId = str_pad(strtolower($traceId), 32, '0', STR_PAD_LEFT);
                            }

                            if (preg_match('/^[a-fA-F0-9]{16}$/', $parentId)) {
                                $parentId = strtolower($parentId);
                            } else {
                                $parentId = self::largeBaseConvert($parentId, 10, 16);
                                $parentId = str_pad(strtolower($parentId), 16, '0', STR_PAD_LEFT);
                            }

                            $spanLinkInstance = new SpanLink();
                            $spanLinkInstance->traceId = $traceId;
                            $spanLinkInstance->spanId = $parentId;
                            $span->links[] = $spanLinkInstance;
                        }
                    }
                }
            }
        );

        // Apart from the span's hook, as for basic_publish. The application handles the message
        // after basic_get returns, so the checkpoint stays the request's pathway until the next.
        \DDTrace\install_hook(
            'PhpAmqpLib\Channel\AMQPChannel::basic_get',
            null,
            static function (HookData $hook) {
                if ($hook->returned instanceof AMQPMessage && \dd_trace_env_config('DD_DATA_STREAMS_ENABLED')) {
                    self::setDataStreamsConsumeCheckpoint($hook->returned, (string)($hook->args[0] ?? ''));
                }
            }
        );

        return Integration::LOADED;
    }

    public static function formatQueueName($queue)
    {
        return empty($queue) || strpos($queue, 'amq.gen-') !== 0
            ? $queue
            : '<generated>';
    }

    public static function formatExchangeName($exchange)
    {
        return empty($exchange) ? '<default>' : $exchange;
    }

    public static function formatRoutingKey($routingKey)
    {
        return empty($routingKey)
            ? '<all>'
            : self::formatQueueName($routingKey);
    }

    public static function setGenericTags(
        SpanData $span,
        string $name,
        string $spanKind,
        $resourceDetail = null,
        $exception = null
    ) {
        $span->name = "amqp.$name";
        $span->resource = "$name" . ($resourceDetail === null ? "" : " $resourceDetail");
        $span->meta[Tag::SPAN_KIND] = $spanKind;
        $span->type = 'queue';
        $span->service = 'amqp';
        $span->meta['_dd.svc_src'] = AMQPIntegration::NAME;
        $span->meta[Tag::COMPONENT] = self::NAME;

        $span->meta[Tag::MQ_SYSTEM] = self::SYSTEM;
        $span->meta[Tag::MQ_DESTINATION_KIND] = 'queue';
        $span->meta[Tag::MQ_PROTOCOL] = 'AMQP';
        $span->meta[Tag::MQ_PROTOCOL_VERSION] = self::$protocolVersion;

        if ($exception) {
            $span->exception = $exception;
        }
    }

    public static function setOptionalMessageTags(SpanData $span, AMQPMessage $message)
    {
        if ($message->has('delivery_mode')) {
            $span->meta[Tag::RABBITMQ_DELIVERY_MODE] = $message->get('delivery_mode');
        }
        if ($message->has('message_id')) {
            $span->meta[Tag::MQ_MESSAGE_ID] = $message->get('message_id');
        }
        if ($message->has('correlation_id')) {
            $span->meta[Tag::MQ_CONVERSATION_ID] = $message->get('correlation_id');
        }
    }

    public static function injectContext(AMQPMessage $message)
    {
        if (\ddtrace_config_distributed_tracing_enabled() === false) {
            return;
        }

        $distributedHeaders = \DDTrace\generate_distributed_tracing_headers();
        if ($message->has('application_headers')) {
            // If the message already has application headers, we need to merge them so user headers are not overwritten
            /** @var AMQPTable $headersObj */
            $headersObj = $message->get('application_headers');
            $headers = $headersObj->getNativeData();
            $headers = array_merge($headers, $distributedHeaders);
            $newHeaders = new AMQPTable($headers);
        } else {
            $newHeaders = new AMQPTable($distributedHeaders);
        }
        $message->set('application_headers', $newHeaders);
    }

    public static function extractContext(AMQPMessage $message)
    {
        if ($message->has('application_headers')) {
            $headers = $message->get('application_headers');
            $headers = $headers->getNativeData();

            \DDTrace\consume_distributed_tracing_headers($headers);
        }
    }

    public static function hasDistributedHeaders(AMQPMessage $message)
    {
        if ($message->has('application_headers')) {
            $headers = $message->get('application_headers');
            $headers = $headers->getNativeData();

            $distributedHeadersKeys = array_keys(\DDTrace\generate_distributed_tracing_headers());

            foreach ($distributedHeadersKeys as $distributedHeaderKey) {
                if (array_key_exists($distributedHeaderKey, $headers)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Set the Data Streams Monitoring checkpoint of a publish, and send its pathway context with
     * the message. As in the Java tracer, a publish to the default exchange is on the edge of the
     * queue its routing key names; any other is on the edge of its exchange.
     */
    public static function setDataStreamsProduceCheckpoint(AMQPMessage $message, $exchange, $routingKey)
    {
        $exchange = (string)$exchange;
        $routingKey = (string)$routingKey;
        if ($exchange === '' && $routingKey !== '') {
            $edgeTags = ['direction:out', 'topic:' . $routingKey, 'type:rabbitmq'];
        } else {
            $edgeTags = [
                'direction:out',
                'exchange:' . $exchange,
                'has_routing_key:' . ($routingKey === '' ? 'false' : 'true'),
                'type:rabbitmq',
            ];
        }

        // The message as it is sent, but for the context header the checkpoint computes.
        $payloadSize = strlen((string)$message->getBody())
            + self::headersSize(self::getNativeHeaders($message), self::DSM_CONTEXT_HEADER);

        $context = \DDTrace\Internal\dsm_set_checkpoint($edgeTags, null, $payloadSize);
        if ($context !== null) {
            self::setDataStreamsContextHeader($message, $context);
        }
    }

    /**
     * Set the Data Streams Monitoring checkpoint of a message consumed from $queue, continuing the
     * pathway context it arrived with, if any.
     */
    public static function setDataStreamsConsumeCheckpoint(AMQPMessage $message, string $queue)
    {
        $headers = self::getNativeHeaders($message);
        $context = $headers[self::DSM_CONTEXT_HEADER] ?? null;
        \DDTrace\Internal\dsm_set_checkpoint(
            ['direction:in', 'topic:' . $queue, 'type:rabbitmq'],
            is_string($context) ? $context : null,
            strlen((string)$message->getBody()) + self::headersSize($headers)
        );
    }

    private static function getNativeHeaders(AMQPMessage $message): array
    {
        if (!$message->has('application_headers')) {
            return [];
        }
        $headers = $message->get('application_headers');
        return $headers instanceof AMQPTable ? $headers->getNativeData() : [];
    }

    /**
     * Bytes of header entries as AMQP encodes them, leaving out $skip and the table's 4-byte
     * length. Estimated from the entries' native values, as encoding them costs several times
     * more: exact for strings, the type of nearly every header, and for the types php-amqplib
     * infers from other values. Numbers a producer gave a narrower or wider type are off by a
     * few bytes, and a value of any other type counts as empty.
     */
    private static function headersSize(array $headers, $skip = null): int
    {
        $size = 0;
        foreach ($headers as $name => $value) {
            if ($name !== $skip) {
                // A table entry is a name length octet, the name, a type octet and the value; an
                // array element only the type octet and the value. AMQP field names can't be
                // numeric, so integer keys are array indexes.
                $size += (is_string($name) ? 2 + strlen($name) : 1) + self::headerValueSize($value);
            }
        }
        return $size;
    }

    private static function headerValueSize($value): int
    {
        if (is_string($value)) {
            return 4 + strlen($value);
        }
        if (is_int($value)) {
            return $value >= -2147483648 && $value <= 2147483647 ? 4 : 8;
        }
        if (is_bool($value)) {
            return 1;
        }
        if (is_float($value) || $value instanceof \DateTimeInterface) {
            return 8;
        }
        if (is_array($value)) {
            return 4 + self::headersSize($value);
        }
        return $value instanceof AMQPDecimal ? 5 : 0;
    }

    public static function setDataStreamsContextHeader(AMQPMessage $message, string $context)
    {
        if ($message->has('application_headers')) {
            $headers = $message->get('application_headers');
            if (!$headers instanceof AMQPTable) {
                return;
            }
            // Set the one entry on a copy: rebuilding the table from its native data would
            // change the AMQP types of the application's own entries (e.g. byte arrays become
            // strings), and the application may reuse the table for other messages.
            $headers = clone $headers;
        } else {
            $headers = new AMQPTable();
        }
        $headers->set(self::DSM_CONTEXT_HEADER, $context, AMQPTable::T_STRING_LONG);
        $message->set('application_headers', $headers);
    }
}
