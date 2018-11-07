<?php

namespace Tests\Integration;

use DDTrace\Tests\Integration\Common\SpanAssertionTrait;
use DDTrace\Tests\Integration\Common\TracerTestTrait;
use DDTrace\Tests\Integration\Frameworks\Symfony\Symfony4ExpectationsProvider;
use DDTrace\Tests\Integration\Frameworks\TestSpecs;
use DDTrace\Tests\Integration\Frameworks\Util\CommonSpecsProvider;
use DDTrace\Tests\Integration\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Integration\Frameworks\Util\Request\RequestSpec;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class CommonSpecsTest extends WebTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    /**
     * @dataProvider provideSpecs
     * @param RequestSpec $spec
     * @param array $spanExpectations
     */
    public function testSpecs(RequestSpec $spec, array $spanExpectations)
    {
        $traces = $this->withTracer(function() use ($spec) {
            if ($spec instanceof GetSpec) {
                $client = static::createClient();
                $client->request('GET', $spec->getPath());
                $response = $client->getResponse();
                $this->assertEquals($spec->getStatusCode(), $response->getStatusCode());
            } else {
                $this->fail('Unhandled request spec type');
            }
        });
        $this->assertExpectedSpans($this, $traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        $specsProvider = new CommonSpecsProvider();
        return $specsProvider->provide(TestSpecs::all(), new Symfony4ExpectationsProvider());
    }
}
