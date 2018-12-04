<?php

namespace DDTrace\Sampling;

use DDTrace\Span;

interface Sampler
{
    public function getPrioritySampling(Span $span);
}
