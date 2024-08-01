<?php

namespace DDTrace\Tests\Integrations\CakePHP\V3_10;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CakePHP/Version_3_10/webroot/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'cakephp_test_app',
            'DD_TRACE_DEBUG' => 'true',
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
                        'cakephp.request',
                        'cakephp_test_app',
                        'web',
                        'GET SimpleController@index'
                    )->withExactTags([
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
                        )->withExactTags([
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
                    )->withExactTags([
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
                        )->withExactTags([
                            Tag::COMPONENT => 'cakephp',
                        ]),
                        SpanAssertion::build(
                            'cakephp.view',
                            'cakephp_test_app',
                            'web',
                            'Simple_view/index.ctp'
                        )->withExactTags([
                            'cakephp.view' => 'Simple_view/index.ctp',
                            Tag::COMPONENT => 'cakephp',
                        ]),
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'cakephp.request',
                        'cakephp_test_app',
                        'web',
                        'GET ErrorController@index'
                    )->withExactTags([
                        'cakephp.route.controller' => 'Error',
                        'cakephp.route.action' => 'index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        'http.route' => '/{controller}',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'cakephp',
                    ])->withExistingTagsNames([
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
                        )->withExactTags([
                            Tag::COMPONENT => 'cakephp',
                        ])->withExistingTagsNames([
                            'error.stack',
                        ])->setError('Exception', 'Foo error'),
                        SpanAssertion::build(
                            'cakephp.view',
                            'cakephp_test_app',
                            'web',
                            'Error/index.ctp'
                        )->withExactTags([
                            'cakephp.view' => 'Error/index.ctp',
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
                    )->withExactTags([
                        'cakephp.route.controller' => 'Parameterized',
                        'cakephp.route.action' => 'customAction',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/parameterized/paramValue',
                        'http.status_code' => '200',
                        'http.route' => '/parameterized/:param',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'cakephp',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Controller.invokeAction',
                            'cakephp_test_app',
                            'web',
                            'Controller.invokeAction'
                        )->withExactTags([
                            Tag::COMPONENT => 'cakephp',
                        ])
                    ]),
                ]
            ]
        );
    }
}
