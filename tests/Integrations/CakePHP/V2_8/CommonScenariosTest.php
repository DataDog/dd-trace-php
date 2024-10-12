<?php

namespace DDTrace\Tests\Integrations\CakePHP\V2_8;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CakePHP/Version_2_8/app/webroot/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'cakephp_test_app',
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
                        'cakephp.route.controller' => 'simple',
                        'cakephp.route.action' => 'index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                        'http.route' => '/:controller',
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
                        'GET SimpleViewController@index'
                    )->withExactTags([
                        'cakephp.route.controller' => 'simple_view',
                        'cakephp.route.action' => 'index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                        'http.route' => '/:controller',
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
                            'SimpleView/index.ctp'
                        )->withExactTags([
                            'cakephp.view' => 'SimpleView/index.ctp',
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
                        'cakephp.route.controller' => 'error',
                        'cakephp.route.action' => 'index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        'http.route' => '/:controller',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'cakephp',
                    ])->withExistingTagsNames([
                        'error.stack'
                    ])->setError(
                        null,
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
                        ])->setError(null, 'Foo error'),
                        SpanAssertion::build(
                            'cakephp.view',
                            'cakephp_test_app',
                            'web',
                            'Errors/index.ctp'
                        )->withExactTags([
                            'cakephp.view' => 'Errors/index.ctp',
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
