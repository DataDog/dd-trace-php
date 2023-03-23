<?php

namespace DDTrace\Tests\Integrations\Laminas\V2_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laminas/Version_2_0/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), ['DD_SERVICE' => 'test_laminas_20']);
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
                        'laminas.request',
                        'test_laminas_20',
                        'web',
                        'Application\Controller\CommonSpecsController@simple simple'
                    )->withExactTags([
                        'laminas.route.name'    => 'simple',
                        'laminas.route.action'  => 'Application\Controller\CommonSpecsController@simple',
                        Tag::HTTP_METHOD        => 'GET',
                        Tag::HTTP_URL           => 'http://localhost:9999/simple?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE   => '200',
                        Tag::SPAN_KIND          => 'server',
                        Tag::COMPONENT          => 'laminas'
                    ])->withChildren([
                        SpanAssertion::exists('laminas.application.init')->withChildren([
                            SpanAssertion::exists('laminas.event.loadModules.post'),
                            SpanAssertion::exists('laminas.event.loadModules'),
                            SpanAssertion::exists('laminas.application.bootstrap')->withChildren([
                                SpanAssertion::exists('laminas.event.bootstrap')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\ViewManager@onBootstrap'
                                    )
                                ])
                            ])
                        ]),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\HttpMethodListener@onRoute',
                                ),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\RouteListener@onRoute'
                                )->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.route.match',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.dispatch')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\MiddlewareListener@onDispatch'
                                ),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\DispatchListener@onDispatch'
                                )->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.controller.dispatch',
                                        'Application\Controller\CommonSpecsController'
                                    )->withChildren([
                                        SpanAssertion::exists(
                                            'laminas.mvcEventListener',
                                            'Application\Controller\CommonSpecsController@onDispatch'
                                        )->withChildren([
                                            SpanAssertion::exists(
                                                'laminas.controller.action',
                                                'Application\Controller\CommonSpecsController@simpleAction'
                                            )
                                        ])
                                    ])
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.finish')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\SendResponseListener@sendResponse'
                                )
                            ])
                        ])
                    ])
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'laminas.request',
                        'test_laminas_20',
                        'web',
                        'Application\Controller\CommonSpecsController@view simpleView'
                    )->withExactTags([
                        'laminas.route.name'    => 'simpleView',
                        'laminas.route.action'  => 'Application\Controller\CommonSpecsController@view',
                        Tag::HTTP_METHOD        => 'GET',
                        Tag::HTTP_URL           => 'http://localhost:9999/simple_view?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE   => '200',
                        Tag::SPAN_KIND          => 'server',
                        Tag::COMPONENT          => 'laminas'
                    ])->withChildren([
                        SpanAssertion::exists('laminas.application.init')->withChildren([
                            SpanAssertion::exists('laminas.event.loadModules.post'),
                            SpanAssertion::exists('laminas.event.loadModules'),
                            SpanAssertion::exists('laminas.application.bootstrap')->withChildren([
                                SpanAssertion::exists('laminas.event.bootstrap')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\ViewManager@onBootstrap'
                                    )
                                ])
                            ])
                        ]),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\HttpMethodListener@onRoute',
                                ),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\RouteListener@onRoute'
                                )->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.route.match',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.dispatch')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\MiddlewareListener@onDispatch'
                                ),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\DispatchListener@onDispatch'
                                )->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.controller.dispatch',
                                        'Application\Controller\CommonSpecsController'
                                    )->withChildren([
                                        SpanAssertion::exists(
                                            'laminas.mvcEventListener',
                                            'Application\Controller\CommonSpecsController@onDispatch'
                                        )->withChildren([
                                            SpanAssertion::exists(
                                                'laminas.controller.action',
                                                'Application\Controller\CommonSpecsController@viewAction'
                                            )
                                        ]),
                                        SpanAssertion::exists(
                                            'laminas.mvcEventListener',
                                            'Laminas\Mvc\View\Http\RouteNotFoundStrategy@prepareNotFoundViewModel'
                                        ),
                                        SpanAssertion::exists(
                                            'laminas.mvcEventListener',
                                            'Laminas\Mvc\View\Http\InjectViewModelListener@injectViewModel'
                                        )
                                    ]),
                                ]),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\View\Http\RouteNotFoundStrategy@prepareNotFoundViewModel'
                                ),
                            ]),
                            SpanAssertion::exists('laminas.application.completeRequest')->withChildren([
                                SpanAssertion::exists('laminas.event.render')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.view.http.renderer',
                                        'Laminas\Mvc\View\Http\DefaultRenderingStrategy@render'
                                    )->withChildren([
                                        SpanAssertion::exists('laminas.view.render')->withChildren([
                                            SpanAssertion::exists(
                                                'laminas.templating.render',
                                                'application/common-specs/view'
                                            )
                                        ])
                                    ])
                                ]),
                                SpanAssertion::exists('laminas.event.finish')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\SendResponseListener@sendResponse'
                                    )
                                ])
                            ])
                        ])
                    ])
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'laminas.request',
                        'test_laminas_20',
                        'web',
                        'Application\Controller\CommonSpecsController@error error'
                    )->withExactTags([
                        'laminas.route.name'    => 'error',
                        'laminas.route.action'  => 'Application\Controller\CommonSpecsController@error',
                        Tag::HTTP_METHOD        => 'GET',
                        Tag::HTTP_URL           => 'http://localhost:9999/error?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE   => '500',
                        Tag::SPAN_KIND          => 'server',
                        Tag::COMPONENT          => 'laminas'
                    ])->setError(
                        'Exception',
                        'Controller error'
                    )->withExistingTagsNames([
                        'error.stack'
                    ])->withChildren([
                        SpanAssertion::exists('laminas.application.init')->withChildren([
                            SpanAssertion::exists('laminas.event.loadModules.post'),
                            SpanAssertion::exists('laminas.event.loadModules'),
                            SpanAssertion::exists('laminas.application.bootstrap')->withChildren([
                                SpanAssertion::exists('laminas.event.bootstrap')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\ViewManager@onBootstrap'
                                    )
                                ])
                            ])
                        ]),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\HttpMethodListener@onRoute',
                                ),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\RouteListener@onRoute'
                                )->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.route.match',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.dispatch')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\MiddlewareListener@onDispatch'
                                ),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\DispatchListener@onDispatch'
                                )->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.controller.dispatch',
                                        'Application\Controller\CommonSpecsController'
                                    )->setError(
                                        'Exception',
                                        'Controller error'
                                    )->withExistingTagsNames([
                                        'error.stack',
                                        Tag::COMPONENT
                                    ])->withChildren([
                                        SpanAssertion::exists(
                                            'laminas.mvcEventListener',
                                            'Application\Controller\CommonSpecsController@onDispatch'
                                        )->setError(
                                            'Exception',
                                            'Controller error'
                                        )->withExistingTagsNames([
                                            'error.stack',
                                            Tag::COMPONENT
                                        ])->withChildren([
                                            SpanAssertion::exists(
                                                'laminas.controller.action',
                                                'Application\Controller\CommonSpecsController@errorAction'
                                            )->setError(
                                                'Exception',
                                                'Controller error'
                                            )->withExistingTagsNames([
                                                'error.stack',
                                                Tag::COMPONENT
                                            ])
                                        ])
                                    ]),
                                    SpanAssertion::exists('laminas.mvcEvent.setError'),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\InjectViewModelListener@injectViewModel'
                                    ),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\ExceptionStrategy@prepareExceptionViewModel'
                                    ),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\RouteNotFoundStrategy@prepareNotFoundViewModel'
                                    ),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\RouteNotFoundStrategy@detectNotFoundError'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.application.completeRequest')->withChildren([
                                SpanAssertion::exists('laminas.event.render')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.view.http.renderer',
                                        'Laminas\Mvc\View\Http\DefaultRenderingStrategy@render'
                                    )->withChildren([
                                        SpanAssertion::exists('laminas.view.render')->withChildren([
                                            SpanAssertion::exists(
                                                'laminas.templating.render',
                                                'error/index'
                                            ),
                                            SpanAssertion::exists(
                                                'laminas.templating.render',
                                                'layout/layout'
                                            )
                                        ])
                                    ])
                                ]),
                                SpanAssertion::exists('laminas.event.finish')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\SendResponseListener@sendResponse'
                                    )
                                ])
                            ])
                        ])
                    ])
                ],
                'A GET request to a missing route' => [
                    SpanAssertion::build(
                        'laminas.request',
                        'test_laminas_20',
                        'web',
                        'GET /does_not_exist'
                    )->withExactTags([
                        Tag::HTTP_METHOD        => 'GET',
                        Tag::HTTP_URL           => 'http://localhost:9999/does_not_exist?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE   => '404',
                        Tag::SPAN_KIND          => 'server',
                        Tag::COMPONENT          => 'laminas'
                    ])->withChildren([
                        SpanAssertion::exists('laminas.application.init')->withChildren([
                            SpanAssertion::exists('laminas.event.loadModules.post'),
                            SpanAssertion::exists('laminas.event.loadModules'),
                            SpanAssertion::exists('laminas.application.bootstrap')->withChildren([
                                SpanAssertion::exists('laminas.event.bootstrap')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\ViewManager@onBootstrap'
                                    )
                                ])
                            ])
                        ]),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\HttpMethodListener@onRoute',
                                ),
                                SpanAssertion::exists(
                                    'laminas.mvcEventListener',
                                    'Laminas\Mvc\RouteListener@onRoute'
                                )->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.route.match',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    ),
                                    SpanAssertion::exists('laminas.mvcEvent.setError'),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\InjectViewModelListener@injectViewModel'
                                    ),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\ExceptionStrategy@prepareExceptionViewModel'
                                    ),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\RouteNotFoundStrategy@prepareNotFoundViewModel'
                                    ),
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\View\Http\RouteNotFoundStrategy@detectNotFoundError'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.application.completeRequest')->withChildren([
                                SpanAssertion::exists('laminas.event.render')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.view.http.renderer',
                                        'Laminas\Mvc\View\Http\DefaultRenderingStrategy@render'
                                    )->withChildren([
                                        SpanAssertion::exists('laminas.view.render')->withChildren([
                                            SpanAssertion::exists(
                                                'laminas.templating.render',
                                                'layout/layout'
                                            ),
                                            SpanAssertion::exists(
                                                'laminas.templating.render',
                                                'error/404'
                                            )
                                        ])
                                    ])
                                ]),
                                SpanAssertion::exists('laminas.event.finish')->withChildren([
                                    SpanAssertion::exists(
                                        'laminas.mvcEventListener',
                                        'Laminas\Mvc\SendResponseListener@sendResponse'
                                    )
                                ])
                            ])
                        ])
                    ])
                ]
            ]
        );
    }
}
