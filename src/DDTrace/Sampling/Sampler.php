<?php

namespace DDTrace\Sampling;

use DDTrace\Contracts\Span;

/**
 * Defines a priority sampling value provider.
 */
interface Sampler
{
    /**
     * Sample the current span. Note the it might have side effects, e.g. setting metrics on the span.
     * We accept to have side effects as we are transitioning to a different architecture, where sampling will be done
     * in the extension, so we keep this as a getter so we do not introduce breaking changes even if this
     * has side effects.
     *
     * @param Span $span
     * @return int
     */
    public function getPrioritySampling(Span $span);
}
