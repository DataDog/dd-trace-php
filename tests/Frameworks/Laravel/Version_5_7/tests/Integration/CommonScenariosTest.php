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
                $response = $this->get($spec->getPath());
                $response->assertStatus($spec->getStatusCode());
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
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_test_app',
                        'web',
                        'App\Http\Controllers\CommonSpecsController@simple simple_route'
                    )->withExactTags([
                        'laravel.route.name' => 'simple_route',
                        'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple',
                        'http.status_code' => '200',
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_test_app',
                        'web',
                        'App\Http\Controllers\CommonSpecsController@simple_view unnamed_route'
                    )->withExactTags([
                        'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple_view',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple_view',
                        'http.status_code' => '200',
                    ])->withExistingTagsNames(['laravel.route.name']),
                    SpanAssertion::build(
                        'laravel.view',
                        'laravel_test_app',
                        'web',
                        'laravel.view'
                    ),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_test_app',
                        'web',
                        'App\Http\Controllers\CommonSpecsController@error unnamed_route'
                    )->withExactTags([
                        'laravel.route.name' => '',
                        'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@error',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error',
                        'http.status_code' => '500',
                    ]),
                ],
            ]
        );
    }
}
