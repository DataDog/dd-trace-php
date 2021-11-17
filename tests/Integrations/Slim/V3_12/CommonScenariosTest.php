<?php

namespace DDTrace\Tests\Integrations\Slim\V3_12;

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
        if (\PHP_MAJOR_VERSION < 7) {
            // Controller's __invoke method is not traced until we support tracing prehook
            // zend_execute_internals so some metadata is missing in 5, e.g. controller name.
            return $this->buildDataProvider(
                [
                    'A simple GET request returning a string' => [
                        SpanAssertion::build(
                            'slim.request',
                            'slim_test_app',
                            'web',
                            'GET simple-route'
                        )->withExactTags([
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:' . self::PORT . '/simple',
                            'http.status_code' => '200',
                        ]),
                    ],
                    'A simple GET request with a view' => [
                        SpanAssertion::build(
                            'slim.request',
                            'slim_test_app',
                            'web',
                            'GET /simple_view'
                        )->withExactTags([
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:' . self::PORT . '/simple_view',
                            'http.status_code' => '200',
                        ])->withChildren([
                            SpanAssertion::build(
                                'slim.view',
                                'slim_test_app',
                                'web',
                                'simple_view.phtml'
                            )->withExactTags([
                                'slim.view' => 'simple_view.phtml',
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
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:' . self::PORT . '/error',
                            'http.status_code' => '500',
                        ])->setError(null, null /* On PHP 5.6 slim error messages are not traced on sandboxed */),
                    ],
                ]
            );
        } else {
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
                            'http.url' => 'http://localhost:9999/simple',
                            'http.status_code' => '200',
                        ])->withChildren([
                            SpanAssertion::build(
                                'slim.route.controller',
                                'slim_test_app',
                                'web',
                                'Closure::__invoke'
                            )
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
                            'http.url' => 'http://localhost:9999/simple_view',
                            'http.status_code' => '200',
                        ])->withChildren([
                            SpanAssertion::build(
                                'slim.route.controller',
                                'slim_test_app',
                                'web',
                                'App\SimpleViewController::index'
                            )->withChildren([
                                SpanAssertion::build(
                                    'slim.view',
                                    'slim_test_app',
                                    'web',
                                    'simple_view.phtml'
                                )->withExactTags([
                                    'slim.view' => 'simple_view.phtml',
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
                            'http.url' => 'http://localhost:9999/error',
                            'http.status_code' => '500',
                        ])->setError(null, null)
                            ->withChildren([
                                SpanAssertion::build(
                                    'slim.route.controller',
                                    'slim_test_app',
                                    'web',
                                    'Closure::__invoke'
                                )->withExistingTagsNames([
                                    'error.stack'
                                ])->setError(null, 'Foo error')
                            ]),
                    ],
                ]
            );
        }
    }
}
