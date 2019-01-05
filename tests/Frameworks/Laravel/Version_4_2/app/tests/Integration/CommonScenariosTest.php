<?php

namespace Tests\Integration;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Frameworks\Util\CommonScenariosDataProviderTrait;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use Tests\TestCase;


class CommonScenariosTest extends TestCase
{
    use TracerTestTrait, SpanAssertionTrait, CommonScenariosDataProviderTrait;

    /**
     * @dataProvider provideSpecs
     * @param RequestSpec $spec
     * @param array $spanExpectations
     */
    public function testScenario(RequestSpec $spec, array $spanExpectations)
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
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build('laravel.request', 'laravel', 'web', 'HomeController@simple simple_route')
                        ->withExactTags([
                            'laravel.route.name' => 'simple_route',
                            'laravel.route.action' => 'HomeController@simple',
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost/simple',
                            'http.status_code' => '200',
                        ]),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::build('laravel.action', 'laravel', 'web', 'simple'),
                    SpanAssertion::exists('laravel.event.handle'),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::exists('laravel.request'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.action'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::build('laravel.view.render', 'laravel', 'web', 'simple_view'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build('laravel.request', 'laravel', 'web', 'HomeController@error error')
                        ->withExactTags([
                            'laravel.route.name' => 'error',
                            'laravel.route.action' => 'HomeController@error',
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost/error',
                            'http.status_code' => '500'
                        ]),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::build('laravel.action', 'laravel', 'web', 'error')
                        ->withExactTags([
                            'error.msg' => 'Controller error',
                            'error.type' => 'Exception',
                        ])
                        ->withExistingTagsNames(['error.stack'])
                        ->setError(),
                    SpanAssertion::exists('laravel.event.handle'),
                ],
            ]
        );
    }
}
