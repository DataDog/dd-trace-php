<?php

namespace DDTrace;

/**
 * Propagator implementations should be able to inject and extract
 * SpanContexts into an implementation specific carrier.
 */
interface Propagator
{
    /**
     * Inject takes the SpanContext and injects it into the carrier using
     * an implementation specific method.
     *
     * @param SpanContext $spanContext
     * @param $carrier
     * @return void
     */
    public function inject(SpanContext $spanContext, &$carrier);

    /**
     * Extract returns the SpanContext from the given carrier using an
     * implementation specific method.
     *
     * @param $carrier
     * @return SpanContext
     */
    public function extract($carrier);
}
