<?php

namespace DDTrace\Integrations\OpenTelemetry;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeys;
use function DDTrace\active_span;
use function DDTrace\generate_distributed_tracing_headers;
use function DDTrace\hook_function;
use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\trace_id;

class OpenTelemetryIntegration extends Integration
{
    const NAME = 'opentelemetry';

    public function getName()
    {
        return self::NAME;
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

    public function init()
    {
        $integration = $this;

        hook_function(
            '\DDTrace\close_span',
            function () {
                return;
                $activeSpan = active_span();
                //print("<<< " . dechex((int)$activeSpan?->id) . "\n");
                $OTelActiveSpan = ObjectKVStore::get($activeSpan, 'otel_span');
                if ($OTelActiveSpan === null) {
                    print("[Inst] No active OTel span\n");
                    return; // No scope to detach
                } else {
                    //Context::storage()->scope()->detach();
                    print("[Inst] Detaching scope\n");
                    $currentScope = Context::storage()->scope();
                    if ($currentScope === null) {
                        print("[Inst] No current scope\n");
                        return; // No scope to detach
                    }
                    $associatedContext = $currentScope->context();
                    /** @var \OpenTelemetry\SDK\Trace\Span $associatedSpan */
                    $associatedSpan = $associatedContext->get(ContextKeys::span());
                    if ($associatedSpan === null) {
                        print("[Inst] No associated span\n");
                        return; // No scope to detach
                    }
                    print("[Inst] Associated span id: {$associatedSpan->getContext()->getSpanId()}\n");
                    if ($associatedSpan->getInstrumentationScope()->getName() === 'datadog') {
                        print("[Inst] Associated span is a DDTrace span\n");
                        Context::storage()->scope()->detach();
                        $OTelActiveSpan->endOTelSpan();
                        return; // No scope to detach
                    }
                    //print("Instrumentation scope: {$associatedSpan->getInstrumentationScope()->getName()}\n");
                     /*
                    if ($associatedSpan === $activeSpan) {
                        print("[Inst] Detaching scope\n");
                        try {
                            $retval = $currentScope->detach();
                            print("[Inst] Retval: $retval\n");
                        } catch (\Throwable $e) {
                            print("[Inst] Exception: {$e->getMessage()}\n");
                            throw $e;
                        }
                    }
                     */
                    //$OTelActiveSpan->end();
                }
            }
        );


        // TODO: Handle close_spans_until

        /*
        hook_method(
            'OpenTelemetry\Context\ContextInterface',
            'getCurrent',
            function () {
                $activeSpan = active_span();
                if ($activeSpan === null) {
                    return; // Nothing to do
                }

                $currentOTelSpan = Span::getCurrent(); // Non-recursive

                $currentOTelSpanId = $currentOTelSpan->getContext()->getSpanId();
                $activeSpanId = str_pad(strtolower(self::largeBaseConvert($activeSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT);
                $currentOTelTraceId = $currentOTelSpan->getContext()->getTraceId();
                $activeTraceId = str_pad(strtolower(self::largeBaseConvert(trace_id(), 10, 16)), 32, '0', STR_PAD_LEFT);

                if (($activeTraceId !== SpanContextValidator::INVALID_TRACE && $activeSpanId !== SpanContextValidator::INVALID_SPAN)
                    && ($activeTraceId !== $currentOTelTraceId || $activeSpanId !== $currentOTelSpanId)) {
                    print(">> current: $currentOTelTraceId $currentOTelSpanId\n");
                    print(">> active: $activeTraceId $activeSpanId\n");
                    // The active span was created with the DDTrace API, so we need to update the OpenTelemetry context
                    $traceContext = generate_distributed_tracing_headers(['tracecontext']);
                    $traceState = new TraceState($traceContext['tracestate']);
                    $traceFlags = substr($traceContext['traceparent'], -2) === '01' ? TraceFlags::SAMPLED : TraceFlags::DEFAULT;
                    $ddSpanContext = SpanContext::create($activeTraceId, $activeSpanId, $traceFlags, $traceState);

                    // Set this span context as the current span context
                    //Context::getCurrent()->with(Context::createKey('ddtrace-span-context'), $ddSpanContext);//->activate();
                    $storage = Context::storage();
                    $ddSpan = Span::wrap($ddSpanContext);
                    $newContext = Context::getCurrent()->withContextValue($ddSpan);
                    $storage->attach($newContext);
                }
            }
        );

        */

        // TODO: Instrument NonRecordingSpan's methods to update the active span
        // Note: The IDs have to match. A NonRecordingSpan isn't necessarily comes from a DDTrace span.
        // TODO: Do it by

        /*
        \DDTrace\install_hook(
            'OpenTelemetry\Context\Context::getCurrent',
            function (\DDTrace\HookData $hook) {
                $currentSpan = Span::getCurrent();
                $activeSpan = active_span();

                $spanId = str_pad(strtolower(self::largeBaseConvert($activeSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT);

                print("> current: {$currentSpan->getContext()->getSpanId()}\n");
                print("> active: $spanId\n");
            }
        );*/

        return Integration::LOADED;
    }
}
