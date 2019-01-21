<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_4_2/public/index.php';
    }

    /**
     * @dataProvider provideSpecs
     * @param RequestSpec $spec
     * @param array $spanExpectations
     * @throws \Exception
     */
    public function testScenario(RequestSpec $spec, array $spanExpectations)
    {
        $traces = $this->tracesFromWebRequest(function () use ($spec) {
            $this->call($spec);
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
                            'http.url' => 'http://localhost:9999/simple',
                            'http.status_code' => '200',
                        ]),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::build('laravel.action', 'laravel', 'web', 'simple'),
                    SpanAssertion::exists('laravel.event.handle'),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::exists('laravel.request'),
                    SpanAssertion::exists('laravel.event.handle'),
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
                            'http.url' => 'http://localhost:9999/error',
                            'http.status_code' => '500'
                        ]),
                    SpanAssertion::exists('laravel.event.handle'),
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
