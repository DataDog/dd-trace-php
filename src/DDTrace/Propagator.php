<?php

namespace DDTrace;

use DDTrace\Contracts\SpanContext as SpanContextInterface;

/**
 * Propagator implementations should be able to inject and extract
 * SpanContexts into an implementation specific carrier.
 */
interface Propagator
{
    const DEFAULT_BAGGAGE_HEADER_PREFIX = 'ot-baggage-';
    const DEFAULT_TRACE_ID_HEADER = 'x-datadog-trace-id';
    const DEFAULT_PARENT_ID_HEADER = 'x-datadog-parent-id';
    const DEFAULT_SAMPLING_PRIORITY_HEADER = 'x-datadog-sampling-priority';

    /**
     * Inject takes the SpanContext and injects it into the carrier using
     * an implementation specific method.
     *
     * @param SpanContextInterface $spanContext
     * @param array|\ArrayAccess $carrier
     * @return void
     */
    public function inject(SpanContextInterface $spanContext, &$carrier);

    /**
     * Extract returns the SpanContext from the given carrier using an
     * implementation specific method.
     *
     * @param array|\ArrayAccess $carrier
     * @return SpanContextInterface
     */
    public function extract($carrier);
}
