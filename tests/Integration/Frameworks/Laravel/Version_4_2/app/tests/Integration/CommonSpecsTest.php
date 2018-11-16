<?php

namespace Tests\Integration;

use DDTrace\Tests\Integration\Common\SpanAssertionTrait;
use DDTrace\Tests\Integration\Common\TracerTestTrait;
use DDTrace\Tests\Integration\Frameworks\Laravel\Laravel4ExpectationsProvider;
use DDTrace\Tests\Integration\Frameworks\TestSpecs;
use DDTrace\Tests\Integration\Frameworks\Util\CommonSpecsProvider;
use DDTrace\Tests\Integration\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Integration\Frameworks\Util\Request\RequestSpec;
use Tests\TestCase;


class CommonSpecsTest extends TestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    /**
     * @dataProvider provideSpecs
     * @param RequestSpec $spec
     * @param array $spanExpectations
     */
    public function testSpecs(RequestSpec $spec, array $spanExpectations)
    {
        $traces = $this->simulateWebRequestTracer(function() use ($spec) {
            if ($spec instanceof GetSpec) {
            $response = $this->call('GET', $spec->getPath());
                $this->assertSame($spec->getStatusCode(), $response->getStatusCode());
            } else {
                $this->fail('Unhandled request spec type');
            }
        });

        $this->assertExpectedSpans($this, $traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        $specsProvider = new CommonSpecsProvider();
        return $specsProvider->provide(TestSpecs::all(), new Laravel4ExpectationsProvider());
    }
}
