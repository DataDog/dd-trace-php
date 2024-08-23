<?php

namespace DDTrace\Tests\Integrations\Slim\V3_12;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_3_12/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'slim_test_app',
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
                        'slim.request',
                        'slim_test_app',
                        'web',
                        'GET simple-route'
                    )->withExactTags([
                        'slim.route.controller' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'slim',
                        Tag::HTTP_ROUTE => '/simple',
                    ])->withChildren([
                        SpanAssertion::build(
                            'slim.route.controller',
                            'slim_test_app',
                            'web',
                            'Closure::__invoke'
                        )->withExactTags([
                            Tag::COMPONENT => 'slim'
                        ])
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'slim.request',
                        'slim_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        'slim.route.controller' => 'App\SimpleViewController::index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'slim',
                        Tag::HTTP_ROUTE => '/simple_view',
                    ])->withChildren([
                        SpanAssertion::build(
                            'slim.route.controller',
                            'slim_test_app',
                            'web',
                            'App\SimpleViewController::index'
                        )->withExactTags([
                            Tag::COMPONENT => 'slim'
                        ])->withChildren([
                            SpanAssertion::build(
                                'slim.view',
                                'slim_test_app',
                                'web',
                                'simple_view.phtml'
                            )->withExactTags([
                                'slim.view' => 'simple_view.phtml',
                                Tag::COMPONENT => 'slim'
                            ])
                        ])
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'slim.request',
                        'slim_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        'slim.route.controller' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'slim',
                        Tag::HTTP_ROUTE => '/error',
                    ])->setError(null, null)
                        ->withChildren([
                            SpanAssertion::build(
                                'slim.route.controller',
                                'slim_test_app',
                                'web',
                                'Closure::__invoke'
                            )->withExactTags([
                                Tag::COMPONENT => 'slim'
                            ])->withExistingTagsNames([
                                'error.stack',
                            ])->setError(null, 'Foo error')
                        ]),
                ],
                'A GET request to a route with a parameter' => [
                    SpanAssertion::build(
                        'slim.request',
                        'slim_test_app',
                        'web',
                        'GET /parameterized/{value}'
                    )->withExactTags([
                        'slim.route.controller' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/parameterized/paramValue',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'slim',
                        Tag::HTTP_ROUTE => '/parameterized/{value}',
                    ])->withChildren([
                        SpanAssertion::build(
                            'slim.route.controller',
                            'slim_test_app',
                            'web',
                            'Closure::__invoke'
                        )->withExactTags([
                            Tag::COMPONENT => 'slim'
                        ])
                    ]),
                ],
            ]
        );
    }
}
