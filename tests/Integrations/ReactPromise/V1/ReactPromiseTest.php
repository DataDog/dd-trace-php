<?php

namespace DDTrace\Tests\Integrations\ReactPromise\V1;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class ReactPromiseTest extends IntegrationTestCase
{
    public function testPromise() {
        $traces = $this->isolateTracer(function () {
            \DDTrace\start_span()->name = "outer";
            $promise = new \React\Promise\Promise(function ($resolve, $reject) {
                $resolve('resolved');
            });
            \DDTrace\close_span();
            $promise->then(function ($value) {
                $this->assertEquals('resolved', $value);
                \DDTrace\start_span()->name = "inner";
                \DDTrace\close_span();
            });
        });

        $this->assertExpectedSpans($traces, [
            SpanAssertion::exists('outer'),
            SpanAssertion::build('inner', 'phpunit', 'cli', 'inner')
                ->withExactTags([
                    '_dd.span_links' => '[{"trace_id":"%s","span_id":"%s"}]',
                ])
        ]);
    }
}
