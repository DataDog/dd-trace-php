<?php

namespace DDTrace\Tests\Integrations\CakePHP\V4_5;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CakePHP/Version_4_5/webroot/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'cakephp_test_app',
            'DD_TRACE_DEBUG' => 'true',
        ]);
    }

    public static function getTestedLibrary()
    {
        return 'cakephp/cakephp';
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
                        'cakephp.request',
                        'cakephp_test_app',
                        'web',
                        'GET SimpleController@index'
                    )->withExactAttributes([
                        'cakephp.route.controller' => 'Simple',
                        'cakephp.route.action' => 'index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                        'http.route' => '/{controller}',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'cakephp',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Controller.invokeAction',
                            'cakephp_test_app',
                            'web',
                            'Controller.invokeAction'
                        )->withExactAttributes([
                            Tag::COMPONENT => 'cakephp',
                        ])
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'cakephp.request',
                        'cakephp_test_app',
                        'web',
                        'GET Simple_viewController@index'
                    )->withExactAttributes([
                        'cakephp.route.controller' => 'Simple_view',
                        'cakephp.route.action' => 'index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                        'http.route' => '/{controller}',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'cakephp',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Controller.invokeAction',
                            'cakephp_test_app',
                            'web',
                            'Controller.invokeAction'
                        )->withExactAttributes([
                            Tag::COMPONENT => 'cakephp',
                        ])->withChildren([
                            SpanAssertion::build(
                                'cakephp.view',
                                'cakephp_test_app',
                                'web',
                                'Simple_view/index.php'
                            )->withExactAttributes([
                                'cakephp.view' => 'Simple_view/index.php',
                                Tag::COMPONENT => 'cakephp',
                            ]),
                        ]),
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'cakephp.request',
                        'cakephp_test_app',
                        'web',
                        'GET ErrorController@index'
                    )->withExactAttributes([
                        'cakephp.route.controller' => 'Error',
                        'cakephp.route.action' => 'index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        'http.route' => '/{controller}',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'cakephp',
                    ])->withExistingAttributeNames([
                        'error.stack'
                    ])->setError(
                        'Exception',
                        'Foo error'
                    )->withChildren([
                        SpanAssertion::build(
                            'Controller.invokeAction',
                            'cakephp_test_app',
                            'web',
                            'Controller.invokeAction'
                        )->withExactAttributes([
                            Tag::COMPONENT => 'cakephp',
                        ])->withExistingAttributeNames([
                            'error.stack',
                        ])->setError('Exception', 'Foo error'),
                        SpanAssertion::build(
                            'cakephp.view',
                            'cakephp_test_app',
                            'web',
                            'Error/error500.php'
                        )->withExactAttributes([
                            'cakephp.view' => 'Error/error500.php',
                            Tag::COMPONENT => 'cakephp',
                        ]),
                    ]),
                ],
                'A GET request to a route with a parameter' => [
                    SpanAssertion::build(
                        'cakephp.request',
                        'cakephp_test_app',
                        'web',
                        'GET ParameterizedController@customAction'
                    )->withExactAttributes([
                        'cakephp.route.controller' => 'Parameterized',
                        'cakephp.route.action' => 'customAction',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/parameterized/paramValue',
                        'http.status_code' => '200',
                        'http.route' => '/parameterized/{param}',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'cakephp',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Controller.invokeAction',
                            'cakephp_test_app',
                            'web',
                            'Controller.invokeAction'
                        )->withExactAttributes([
                            Tag::COMPONENT => 'cakephp',
                        ])
                    ]),
                ]
            ]
        );
    }
}