<?php

namespace DDTrace\Tests\Integrations\Slim\V4;

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
                        'http.url' => 'http://localhost:9999/simple',
                        'http.status_code' => '200',
                    ])->withChildren([
                        SpanAssertion::build(
                            'slim.route',
                            'slim_test_app',
                            'web',
                            'Closure::__invoke'
                        )->withExactTags([
                            'slim.route.name' => 'simple-route',
                        ])
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
                        'http.url' => 'http://localhost:9999/simple_view',
                        'http.status_code' => '200',
                    ])->withChildren([
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
                            ])
                        ])
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
                        'http.url' => 'http://localhost:9999/error',
                        'http.status_code' => '500',
                    ])->setError(null, null)
                        ->withChildren([
                            SpanAssertion::build(
                                'slim.route',
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
