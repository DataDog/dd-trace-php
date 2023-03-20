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
                        SpanAssertion::exists('laminas.event.bootstrap'),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::build(
                                    'laminas.route.listener',
                                    'test_laminas_20',
                                    'web',
                                    'Laminas\Mvc\RouteListener'
                                )->withChildren([
                                    SpanAssertion::build(
                                        'laminas.route.match',
                                        'test_laminas_20',
                                        'web',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.dispatch')->withChildren([
                                SpanAssertion::build(
                                    'laminas.controller.dispatch',
                                    'test_laminas_20',
                                    'web',
                                    'Application\Controller\CommonSpecsController'
                                )->withChildren([
                                    SpanAssertion::build(
                                        'laminas.controller.action',
                                        'test_laminas_20',
                                        'web',
                                        'Application\Controller\CommonSpecsController@simple'
                                    )->withExactTags([
                                        Tag::COMPONENT => 'laminas'
                                    ])
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.finish')->withChildren([
                                SpanAssertion::exists('laminas.send_response')
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
                        SpanAssertion::exists('laminas.event.bootstrap'),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::build(
                                    'laminas.route.listener',
                                    'test_laminas_20',
                                    'web',
                                    'Laminas\Mvc\RouteListener'
                                )->withChildren([
                                    SpanAssertion::build(
                                        'laminas.route.match',
                                        'test_laminas_20',
                                        'web',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.dispatch')->withChildren([
                                SpanAssertion::build(
                                    'laminas.controller.dispatch',
                                    'test_laminas_20',
                                    'web',
                                    'Application\Controller\CommonSpecsController'
                                )->withChildren([
                                    SpanAssertion::build(
                                        'laminas.controller.action',
                                        'test_laminas_20',
                                        'web',
                                        'Application\Controller\CommonSpecsController@view'
                                    )->withExactTags([
                                        Tag::COMPONENT => 'laminas'
                                    ])
                                ])
                            ]),
                            SpanAssertion::exists('laminas.application.complete_request')->withChildren([
                                SpanAssertion::exists('laminas.event.render')->withChildren([
                                    SpanAssertion::build(
                                        'laminas.view.http.renderer',
                                        'test_laminas_20',
                                        'web',
                                        'Laminas\Mvc\View\Http\DefaultRenderingStrategy@render'
                                    )->withChildren([
                                        SpanAssertion::build(
                                            'laminas.view.renderer',
                                            'test_laminas_20',
                                            'web',
                                            'application/common-specs/view'
                                        )->withExactTags([
                                            Tag::COMPONENT => 'laminas'
                                        ])
                                    ])
                                ]),
                                SpanAssertion::exists('laminas.event.finish')->withChildren([
                                    SpanAssertion::exists('laminas.send_response')
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
                        SpanAssertion::exists('laminas.event.bootstrap'),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::build(
                                    'laminas.route.listener',
                                    'test_laminas_20',
                                    'web',
                                    'Laminas\Mvc\RouteListener'
                                )->withChildren([
                                    SpanAssertion::build(
                                        'laminas.route.match',
                                        'test_laminas_20',
                                        'web',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    )
                                ])
                            ]),
                            SpanAssertion::exists('laminas.event.dispatch')->withChildren([
                                SpanAssertion::build(
                                    'laminas.controller.dispatch',
                                    'test_laminas_20',
                                    'web',
                                    'Application\Controller\CommonSpecsController'
                                )->setError(
                                    'Exception',
                                    'Controller error'
                                )->withExistingTagsNames([
                                    'error.stack'
                                ])->withChildren([
                                    SpanAssertion::build(
                                        'laminas.controller.action',
                                        'test_laminas_20',
                                        'web',
                                        'Application\Controller\CommonSpecsController@error'
                                    )->withExactTags([
                                        Tag::COMPONENT => 'laminas'
                                    ])->setError(
                                        'Exception',
                                        'Controller error'
                                    )->withExistingTagsNames([
                                        'error.stack'
                                    ])
                                ]),
                                SpanAssertion::exists('laminas.mvc_event.set_error')
                            ]),
                            SpanAssertion::exists('laminas.application.complete_request')->withChildren([
                                SpanAssertion::exists('laminas.event.render')->withChildren([
                                    SpanAssertion::build(
                                        'laminas.view.http.renderer',
                                        'test_laminas_20',
                                        'web',
                                        'Laminas\Mvc\View\Http\DefaultRenderingStrategy@render'
                                    )->withChildren([
                                        SpanAssertion::build(
                                            'laminas.view.renderer',
                                            'test_laminas_20',
                                            'web',
                                            'error/index'
                                        )->withExactTags([
                                            Tag::COMPONENT => 'laminas'
                                        ]),
                                        SpanAssertion::build(
                                            'laminas.view.renderer',
                                            'test_laminas_20',
                                            'web',
                                            'layout/layout'
                                        )->withExactTags([
                                            Tag::COMPONENT => 'laminas'
                                        ])
                                    ])
                                ]),
                                SpanAssertion::exists('laminas.event.finish')->withChildren([
                                    SpanAssertion::exists('laminas.send_response')
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
                        SpanAssertion::exists('laminas.event.bootstrap'),
                        SpanAssertion::exists('laminas.application.run')->withChildren([
                            SpanAssertion::exists('laminas.event.route')->withChildren([
                                SpanAssertion::build(
                                    'laminas.route.listener',
                                    'test_laminas_20',
                                    'web',
                                    'Laminas\Mvc\RouteListener'
                                )->withChildren([
                                    SpanAssertion::build(
                                        'laminas.route.match',
                                        'test_laminas_20',
                                        'web',
                                        'Laminas\Router\Http\TreeRouteStack@match'
                                    ),
                                    SpanAssertion::exists('laminas.mvc_event.set_error')
                                ])
                            ]),
                            SpanAssertion::exists('laminas.application.complete_request')->withChildren([
                                SpanAssertion::exists('laminas.event.render')->withChildren([
                                    SpanAssertion::build(
                                        'laminas.view.http.renderer',
                                        'test_laminas_20',
                                        'web',
                                        'Laminas\Mvc\View\Http\DefaultRenderingStrategy@render'
                                    )->withChildren([
                                        SpanAssertion::build(
                                            'laminas.view.renderer',
                                            'test_laminas_20',
                                            'web',
                                            'error/404'
                                        )->withExactTags([
                                            Tag::COMPONENT => 'laminas'
                                        ]),
                                        SpanAssertion::build(
                                            'laminas.view.renderer',
                                            'test_laminas_20',
                                            'web',
                                            'layout/layout'
                                        )->withExactTags([
                                            Tag::COMPONENT => 'laminas'
                                        ])
                                    ])
                                ]),
                                SpanAssertion::exists('laminas.event.finish')->withChildren([
                                    SpanAssertion::exists('laminas.send_response')
                                ])
                            ])
                        ])
                    ])
                ]
            ]
        );
    }
}
