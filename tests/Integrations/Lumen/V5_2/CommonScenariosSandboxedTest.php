<?php

namespace DDTrace\Tests\Integrations\Lumen\V5_2;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosSandboxedTest extends CommonScenariosTest
{
    const IS_SANDBOX = true;

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

        $this->assertFlameGraph($traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => $this->getSimpleTrace(),
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'lumen.request',
                        'lumen_test_app',
                        'web',
                        'GET App\Http\Controllers\ExampleController@simpleView'
                    )->withExactTags([
                        'lumen.route.action' => 'App\Http\Controllers\ExampleController@simpleView',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple_view',
                        'http.status_code' => '200',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Laravel\Lumen\Application.handleFoundRoute',
                            'lumen_test_app',
                            'web',
                            'Laravel\Lumen\Application.handleFoundRoute'
                        )->withExactTags([])
                        ->withChildren([
                            SpanAssertion::build(
                                'laravel.view.render',
                                'lumen_test_app',
                                'web',
                                'simple_view'
                            )->withExactTags([])
                            ->withChildren([
                                SpanAssertion::build(
                                    'lumen.view',
                                    'lumen_test_app',
                                    'web',
                                    '*/resources/views/simple_view.blade.php'
                                )->withExactTags([]),
                                SpanAssertion::build(
                                    'laravel.event.handle',
                                    'lumen_test_app',
                                    'web',
                                    'composing: simple_view'
                                )->withExactTags([]),
                            ]),
                            SpanAssertion::build(
                                'laravel.event.handle',
                                'lumen_test_app',
                                'web',
                                'creating: simple_view'
                            )->withExactTags([])
                        ])
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'lumen.request',
                        'lumen_test_app',
                        'web',
                        'GET App\Http\Controllers\ExampleController@error'
                    )->withExactTags([
                        'lumen.route.action' => 'App\Http\Controllers\ExampleController@error',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/error',
                        'http.status_code' => '500',
                    ])->withExistingTagsNames(\PHP_MAJOR_VERSION === 5 ? [] : ['error.stack'])
                    ->setError(
                        \PHP_MAJOR_VERSION === 5 ? 'Internal Server Error' : 'Exception',
                        \PHP_MAJOR_VERSION === 5 ? null : 'Controller error'
                    )->withChildren([
                        SpanAssertion::build(
                            'Laravel\Lumen\Application.handleFoundRoute',
                            'lumen_test_app',
                            'web',
                            'Laravel\Lumen\Application.handleFoundRoute'
                        )->withExistingTagsNames([
                            'error.stack'
                        ])->setError('Exception', 'Controller error'),
                        SpanAssertion::build(
                            'Laravel\Lumen\Application.sendExceptionToHandler',
                            'lumen_test_app',
                            'web',
                            'Laravel\Lumen\Application.sendExceptionToHandler'
                        ),
                    ]),
                ],
            ]
        );
    }
}
