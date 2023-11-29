<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
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
                        'http.url' => 'http://localhost:9999/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                        'http.route' => 'simple',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel'
                    ])->withChildren([
                        SpanAssertion::build(
                            'laravel.action',
                            'laravel_test_app',
                            'web',
                            'simple'
                        )->withExactTags([
                            TAG::COMPONENT => 'laravel'
                        ]),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load',
                            null,
                            'laravel_test_app'
                        ),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_test_app',
                        'web',
                        'App\Http\Controllers\CommonSpecsController@simple_view unnamed_route'
                    )->withExactTags([
                        'laravel.route.name' => 'unnamed_route',
                        'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple_view',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                        'http.route' => 'simple_view',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel'
                    ])->withChildren([
                        SpanAssertion::build('laravel.action', 'laravel_test_app', 'web', 'simple_view')
                        ->withExactTags([
                            TAG::COMPONENT => 'laravel'
                        ])->withChildren([
                            SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        ]),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load',
                            null,
                            'laravel_test_app'
                        ),
                        SpanAssertion::build(
                            'laravel.view.render',
                            'laravel_test_app',
                            'web',
                            'simple_view'
                        )->withExactTags([
                            TAG::COMPONENT => 'laravel'
                        ])->withChildren([
                            SpanAssertion::build(
                                'laravel.view',
                                'laravel_test_app',
                                'web',
                                '*/resources/views/simple_view.blade.php'
                            )->withExactTags([
                                TAG::COMPONENT => 'laravel'
                            ]),
                            SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        ]),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_test_app',
                        'web',
                        'App\Http\Controllers\CommonSpecsController@error unnamed_route'
                    )->withExactTags([
                        'laravel.route.name' => 'unnamed_route',
                        'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@error',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        'http.route' => 'error',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel'
                    ])->setError('Exception', 'Controller error', true)->withChildren([
                        SpanAssertion::exists('laravel.action'),
                        SpanAssertion::exists('laravel.view.render')
                            ->withChildren([
                                SpanAssertion::exists('laravel.view')->withChildren([
                                    SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                                    SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                                ]),
                                SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                            ]),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load',
                            null,
                            'laravel_test_app'
                        ),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                    ]),
                ],
                'A GET request to a missing route' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_test_app',
                        'web',
                        'GET /does_not_exist'
                    )->withExactTags([
                        Tag::HTTP_URL => 'http://localhost:9999/does_not_exist?key=value&<redacted>',
                        Tag::HTTP_METHOD => 'GET',
                        Tag::COMPONENT => 'laravel',
                        Tag::HTTP_STATUS_CODE => '404',
                    ])->withChildren([
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.provider.load'),
                        SpanAssertion::build(
                            'laravel.view.render',
                            'laravel_test_app',
                            'web',
                            'errors::404'
                        )->withExactTags([
                            TAG::COMPONENT => 'laravel'
                        ])->withChildren([
                            SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                            SpanAssertion::build(
                                'laravel.view',
                                'laravel_test_app',
                                'web',
                                '*/src/Illuminate/Foundation/Exceptions/views/404.blade.php'
                            )->withExactTags([
                                TAG::COMPONENT => 'laravel'
                            ])->withChildren([
                                SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                                SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app')
                            ])
                        ])
                    ])
                ],
                'A GET request to a dynamic route returning a string' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_test_app',
                        'web',
                        'App\Http\Controllers\CommonSpecsController@dynamicRoute unnamed_route'
                    )->withExactTags([
                        'laravel.route.name' => 'unnamed_route',
                        'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@dynamicRoute',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/dynamic_route/dynamic01/static/dynamic02',
                        'http.status_code' => '200',
                        'http.route' => 'dynamic_route/{param01}/static/{param02?}',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel'
                    ])->withChildren([
                        SpanAssertion::exists('PDO.__construct', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('PDO.query', null, null, 'laravel_test_app'),
                        SpanAssertion::build(
                            'laravel.action',
                            'laravel_test_app',
                            'web',
                            'dynamic_route/{param01}/static/{param02?}'
                        )->withExactTags([
                            TAG::COMPONENT => 'laravel'
                        ]),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load',
                            null,
                            'laravel_test_app'
                        ),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                        SpanAssertion::exists('laravel.event.handle', null, null, 'laravel_test_app'),
                    ]),
                ]
            ]
        );
    }
}
