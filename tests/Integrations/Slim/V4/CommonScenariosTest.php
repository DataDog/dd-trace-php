<?php

namespace DDTrace\Tests\Integrations\Slim\V4;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_4/public/index.php';
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

    private function wrapMiddleware(array $children, array $setError = []): SpanAssertion
    {
        if (!empty($setError)) {
            return SpanAssertion::build(
                'slim.middleware',
                'slim_test_app',
                'web',
                'Slim\\Middleware\\ErrorMiddleware'
            )->withChildren([
                SpanAssertion::build(
                    'slim.middleware',
                    'slim_test_app',
                    'web',
                    'Slim\Middleware\RoutingMiddleware'
                )->withChildren([
                    SpanAssertion::build(
                        'slim.middleware',
                        'slim_test_app',
                        'web',
                        'Slim\\Views\\TwigMiddleware'
                    )
                    ->withChildren($children)
                    ->withExistingTagsNames(['error.stack'])
                    ->setError(...$setError)
                ])->withExistingTagsNames(['error.stack'])->setError(...$setError),
            ])/* ->setError(...$setError) ; no error on ErrorMiddleware*/;
        } else {
            return SpanAssertion::build(
                'slim.middleware',
                'slim_test_app',
                'web',
                'Slim\\Middleware\\ErrorMiddleware'
            )->withChildren([
                SpanAssertion::build(
                    'slim.middleware',
                    'slim_test_app',
                    'web',
                    'Slim\Middleware\RoutingMiddleware'
                )->withChildren([
                    SpanAssertion::build(
                        'slim.middleware',
                        'slim_test_app',
                        'web',
                        'Slim\\Views\\TwigMiddleware'
                    )->withChildren($children)
                ]),
            ]);
        }
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'web.request',
                        'slim_test_app',
                        'web',
                        'GET /simple'
                    )->withExactTags([
                        'slim.route.name' => 'simple-route',
                        'slim.route.handler' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => 'server',
                    ])->withChildren([
                        $this->wrapMiddleware([
                            SpanAssertion::build(
                                'slim.route',
                                'slim_test_app',
                                'web',
                                'Closure::__invoke'
                            )->withExactTags([
                                'slim.route.name' => 'simple-route',
                                Tag::SPAN_KIND => 'server',
                            ])
                        ]),
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'web.request',
                        'slim_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        'slim.route.handler' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => 'server',
                    ])->withChildren([
                        $this->wrapMiddleware([
                            SpanAssertion::build(
                                'slim.route',
                                'slim_test_app',
                                'web',
                                'Closure::__invoke'
                            )->withChildren([
                                SpanAssertion::build(
                                    'slim.view',
                                    'slim_test_app',
                                    'web',
                                    'simple_view.phtml'
                                )->withExactTags([
                                    'slim.view' => 'simple_view.phtml',
                                ]),
                            ]),
                        ]),
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'web.request',
                        'slim_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        'slim.route.handler' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        Tag::SPAN_KIND => 'server',
                    ])
                    ->setError(null, null)
                    ->withChildren([
                        $this->wrapMiddleware(
                            [
                                SpanAssertion::build(
                                    'slim.route',
                                    'slim_test_app',
                                    'web',
                                    'Closure::__invoke'
                                )->withExistingTagsNames([
                                    'error.stack'
                                ])->setError(null, 'Foo error')
                            ],
                            [null, 'Foo error']
                        )
                    ]),
                ],
            ]
        );
    }
}
