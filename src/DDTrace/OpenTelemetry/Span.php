<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use DDTrace\Log\Logger;
use DDTrace\Propagator;
use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\Tag;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeys;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesBuilderInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanLimits;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\SDK\Trace\StatusDataInterface;
use Throwable;

use function DDTrace\close_span;
use function DDTrace\close_spans_until;
use function DDTrace\generate_distributed_tracing_headers;
use function DDTrace\start_trace_span;
use function DDTrace\trace_id;

final class Span extends API\Span implements ReadWriteSpanInterface
{
    private SpanData $span;

    /** @readonly */
    private API\SpanContextInterface $context;

    /** @readonly */
    private API\SpanContextInterface $parentSpanContext;

    /** @readonly */
    private SpanProcessorInterface $spanProcessor;

    /** @readonly */
    private int $kind;

    /** @readonly */
    private ResourceInfo $resource;

    /** @readonly */
    private InstrumentationScopeInterface $instrumentationScope;

    private AttributesBuilderInterface $attributesBuilder;
    private StatusDataInterface $status;
    private bool $hasEnded = false;

    private function __construct(
        SpanData $span,
        API\SpanContextInterface $context,
        InstrumentationScopeInterface $instrumentationScope,
        int $kind,
        API\SpanContextInterface $parentSpanContext,
        SpanProcessorInterface $spanProcessor,
        ResourceInfo $resource,
        AttributesBuilderInterface $attributesBuilder
    ) {
        $this->span = $span;
        $this->context = $context;
        $this->instrumentationScope = $instrumentationScope;
        $this->kind = $kind;
        $this->parentSpanContext = $parentSpanContext;
        $this->spanProcessor = $spanProcessor;
        $this->resource = $resource;
        $this->attributesBuilder = $attributesBuilder;

        $this->status = StatusData::unset();
    }

    /**
     * This method _MUST_ not be used directly.
     * End users should use a {@see API\TracerInterface} in order to create spans.
     *
     * @param non-empty-string $name
     * @psalm-param API\SpanKind::KIND_* $kind
     * @param list<LinkInterface> $links
     *
     * @internal
     * @psalm-internal OpenTelemetry
     */
    public static function startSpan(
        SpanData $span,
        API\SpanContextInterface $context,
        InstrumentationScopeInterface $instrumentationScope,
        int $kind,
        API\SpanInterface $parentSpan,
        ContextInterface $parentContext,
        SpanProcessorInterface $spanProcessor,
        ResourceInfo $resource,
        AttributesBuilderInterface $attributesBuilder,
        array $links,
        int $totalRecordedLinks,
        float $startEpochNanos = 0
    ): self {
        $attributes = $attributesBuilder->build()->toArray();
        self::_setAttributes($span, $attributes);

        $OTelSpan = new self(
            $span,
            $context,
            $instrumentationScope,
            $kind,
            $parentSpan->getContext(),
            $spanProcessor,
            $resource,
            $attributesBuilder
        );

        ObjectKVStore::put($span, 'otel_span', $OTelSpan);
        print("[OTel] Created span {$OTelSpan->getContext()->getSpanId()}\n");

        // Call onStart here to ensure the span is fully initialized.
        $spanProcessor->onStart($OTelSpan, $parentContext);

        return $OTelSpan;
    }

    public function getName(): string
    {
        return $this->span->name;
    }

    // Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
    private static function largeBaseConvert($numString, $fromBase, $toBase)
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
     * @inheritDoc
     */
    public function getContext(): SpanContextInterface
    {
        return $this->context;
    }

    public function getParentContext(): API\SpanContextInterface
    {
        return $this->parentSpanContext;
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->instrumentationScope;
    }

    public function hasEnded(): bool
    {
        return $this->hasEnded;
    }

    /**
     * @inheritDoc
     */
    public function toSpanData(): SpanDataInterface
    {
        return new ImmutableSpan(
            $this,
            $this->getName(),
            [], // TODO: Handle Span Links
            [], // TODO: Handle Span Events
            $this->attributesBuilder->build(),
            0,
            StatusData::create($this->status->getCode(), $this->status->getDescription()),
            $this->hasEnded ? $this->getDuration() : 0,
            $this->hasEnded
        );
    }

    /**
     * @inheritDoc
     */
    public function getDuration(): int
    {
        return $this->hasEnded
            ? $this->span->getDuration()
            : (int) (microtime(true) * 1000000000) - $this->span->getStartTime();
    }

    /**
     * @inheritDoc
     */
    public function getKind(): int
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getAttribute(string $key)
    {
        return $this->attributesBuilder[$key];
    }

    public function getStartEpochNanos(): int
    {
        return $this->span->getStartTime();
    }

    /**
     * @inheritDoc
     */
    public function isRecording(): bool
    {
        return !$this->hasEnded;
    }

    private static function _setAttributes(SpanData $span, iterable $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if ($key === Tag::RESOURCE_NAME) {
                $span->resource = $value;
            } elseif (strpos($key, '_dd.p.') === 0) {
                $distributedKey = substr($key, 6); // strlen('_dd.p.') === 6
                \DDTrace\add_distributed_tag($distributedKey, $value);
            } else {
                $span->meta[$key] = $value;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function setAttribute(string $key, $value): SpanInterface
    {
        if (!$this->hasEnded) {
            if ($key === Tag::RESOURCE_NAME) {
                $this->span->resource = $value;
            } elseif (strpos($key, '_dd.p.') === 0) {
                $distributedKey = substr($key, 6); // strlen('_dd.p.') === 6
                \DDTrace\add_distributed_tag($distributedKey, $value);
                //$this->attributesBuilder[$key] = $value; // Counts towards the attribute limit
            } elseif ($this->instrumentationScope->getName() === 'datadog') {
                $this->span->meta[$key] = $value;
                $this->attributesBuilder[$key] = $value;
            } else {// TODO: Horrible workaround for now
                $this->attributesBuilder[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null): SpanInterface
    {
        // no-op
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface
    {
        if (!$this->hasEnded) {
            $this->span->meta[Tag::ERROR_MSG] = $exception->getMessage();
            $this->span->meta[Tag::ERROR_TYPE] = get_class($exception);
            $this->span->meta[Tag::ERROR_STACK] = $exception->getTraceAsString();
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function updateName(string $name): SpanInterface
    {
        if (!$this->hasEnded) {
            $this->span->name = $name;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $code, string $description = null): SpanInterface
    {
        if ($this->hasEnded) {
            return $this;
        }

        // An attempt to set value Unset SHOULD be ignored.
        if ($code === API\StatusCode::STATUS_UNSET) {
            return $this;
        }

        // When span status is set to Ok it SHOULD be considered final and any further attempts to change it SHOULD be ignored.
        if ($this->status->getCode() === API\StatusCode::STATUS_OK) {
            return $this;
        }

        if ($this->status->getCode() === API\StatusCode::STATUS_UNSET && $code === API\StatusCode::STATUS_ERROR) {
            $this->span->meta[Tag::ERROR_MSG] = $description;
        } elseif ($this->status->getCode() === API\StatusCode::STATUS_ERROR && $code === API\StatusCode::STATUS_OK) {
            unset($this->span->meta[Tag::ERROR_MSG]);
            unset($this->span->meta[Tag::ERROR_TYPE]);
            unset($this->span->meta[Tag::ERROR_STACK]);
        }

        $this->status = StatusData::create($code, $description);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function end(int $endEpochNanos = null): void
    {
        $this->endOTelSpan($endEpochNanos);

        // TODO: Actually check if the span was closed (change extension to return a boolean?)
        //close_spans_until($this->span);
        close_span();
        //close_span($endEpochNanos !== null ? $endEpochNanos / 1000000000 : 0);
    }

    public function endOTelSpan(int $endEpochNanos = null): void
    {
        if ($this->hasEnded) {
            return;
        }

        $attributes = $this->attributesBuilder->build()->toArray();
        self::_setAttributes($this->span, $attributes);

        // After closing the span, the context won't change, but would otherwise be lost
        $this->context = API\SpanContext::create(
            $this->context->getTraceId(),
            $this->context->getSpanId(),
            $this->context->getTraceFlags(),
            $this->context->getTraceState()
        );

        $this->hasEnded = true;

        $this->spanProcessor->onEnd($this);
    }

    public function getResource(): ResourceInfo
    {
        return $this->resource;
    }

    public function getDDSpan(): SpanData
    {
        return $this->span;
    }

    // __destruct() --> detach scope if active
    public function __destruct()
    {
        /*
        print("Destructing span\n");
        if ($this->hasEnded) {
            return;
        }

        $currentScope = Context::storage()->scope();
        $associatedContext = $currentScope->context();
        $associatedSpan = $associatedContext->get(ContextKeys::span());
        if ($associatedSpan === $this) {
            print("Detaching scope\n");
            $currentScope->detach();
        }

        $this->end();
        */
    }
}
