<?php

namespace DDTrace\Tests\Integration\Frameworks\Util;


use DDTrace\Tests\Integration\Common\SpanAssertion;

interface ExpectationProvider
{
    /**
     * @return SpanAssertion[]
     */
    public function provide();
}
