<?php

namespace DDTrace\Tests\Integrations\Lumen\V5_2;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Lumen/Version_5_2/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'lumen_test_app'
        ]);
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
                        'GET /simple_view'
                    )->withExactTags([
                        'lumen.route.action' => 'App\Http\Controllers\ExampleController@simpleView',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'lumen'
                    ])->withChildren([
                        SpanAssertion::build(
                            'Laravel\Lumen\Application.handleFoundRoute',
                            'lumen_test_app',
                            'web',
                            'Laravel\Lumen\Application.handleFoundRoute'
                        )->withExactTags([
                            'lumen.route.action' => 'App\Http\Controllers\ExampleController@simpleView',
                            TAG::COMPONENT => 'lumen'
                        ])
                        ->withChildren([
                            SpanAssertion::build(
                                'laravel.view.render',
                                'lumen_test_app',
                                'web',
                                'simple_view'
                            )->withExactTags([
                                TAG::COMPONENT => 'laravel'
                            ])->withChildren([
                                SpanAssertion::build(
                                    'lumen.view',
                                    'lumen_test_app',
                                    'web',
                                    '*/resources/views/simple_view.blade.php'
                                )->withExactTags([
                                    TAG::COMPONENT => 'lumen'
                                ]),
                                SpanAssertion::build(
                                    'laravel.event.handle',
                                    'lumen_test_app',
                                    'web',
                                    'composing: simple_view'
                                )->withExactTags([
                                    TAG::COMPONENT => 'laravel'
                                ])
                            ]),
                            SpanAssertion::build(
                                'laravel.event.handle',
                                'lumen_test_app',
                                'web',
                                'creating: simple_view'
                            )->withExactTags([
                                TAG::COMPONENT => 'laravel'
                            ])
                        ])
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'lumen.request',
                        'lumen_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        'lumen.route.action' => 'App\Http\Controllers\ExampleController@error',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        Tag::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'lumen'
                    ])->withExistingTagsNames([
                        'error.stack'
                    ])->setError(
                        'Exception',
                        'Controller error',
                        true
                    )->withChildren([
                        SpanAssertion::build(
                            'Laravel\Lumen\Application.handleFoundRoute',
                            'lumen_test_app',
                            'web',
                            'Laravel\Lumen\Application.handleFoundRoute'
                        )->withExactTags([
                            'lumen.route.action' => 'App\Http\Controllers\ExampleController@error',
                            TAG::COMPONENT => 'lumen'
                        ])
                        ->withExistingTagsNames([
                            'error.stack'
                        ])->setError('Exception', 'Controller error'),
                        SpanAssertion::build(
                            'Laravel\Lumen\Application.sendExceptionToHandler',
                            'lumen_test_app',
                            'web',
                            'Laravel\Lumen\Application.sendExceptionToHandler'
                        )->withExactTags([
                            TAG::COMPONENT => 'lumen',
                        ]),
                    ]),
                ],
            ]
        );
    }

    protected function getSimpleTrace()
    {
        return [
            SpanAssertion::build(
                'lumen.request',
                'lumen_test_app',
                'web',
                'GET /simple'
            )->withExactTags([
                'lumen.route.name' => 'simple_route',
                'lumen.route.action' => 'App\Http\Controllers\ExampleController@simple',
                'http.method' => 'GET',
                'http.url' => 'http://localhost/simple?key=value&<redacted>',
                'http.status_code' => '200',
                Tag::SPAN_KIND => 'server',
                TAG::COMPONENT => 'lumen'
            ])->withChildren([
                SpanAssertion::build(
                    'Laravel\Lumen\Application.handleFoundRoute',
                    'lumen_test_app',
                    'web',
                    'simple_route'
                )->withExactTags([
                    'lumen.route.action' => 'App\Http\Controllers\ExampleController@simple',
                    TAG::COMPONENT => 'lumen'
                ]),
            ]),
        ];
    }

    protected function getErrorTrace()
    {
        return [
            SpanAssertion::build(
                'lumen.request',
                'lumen_test_app',
                'web',
                'GET /error'
            )->withExactTags([
                'lumen.route.action' => 'App\Http\Controllers\ExampleController@error',
                'http.method' => 'GET',
                'http.url' => 'http://localhost/error?key=value&<redacted>',
                'http.status_code' => '500',
                Tag::SPAN_KIND => 'server',
                TAG::COMPONENT => 'lumen'
            ])->setError()
                ->withChildren([
                    SpanAssertion::build(
                        'Laravel\Lumen\Application.handleFoundRoute',
                        'lumen_test_app',
                        '',
                        'Laravel\Lumen\Application.handleFoundRoute'
                    )->withExactTags([
                        'lumen.route.action' => 'App\Http\Controllers\ExampleController@error',
                        TAG::COMPONENT => 'lumen'
                    ])->withExistingTagsNames([
                        'error.stack'
                    ])->setError('Exception', 'Controller error'),
                ]),
        ];
    }
}
