<?php

namespace DDTrace\Tests\Integrations\CakePHP\V2_8;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CakePHP/Version_2_8/app/webroot/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'cakephp_test_app',
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

        $this->assertExpectedSpans($this, $traces, $spanExpectations);
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
                        'http.url' => 'http://localhost:9999/simple',
                        'http.status_code' => '200',
                        'integration.name' => 'cakephp',
                    ]),
                    SpanAssertion::exists('cakephp.event', 'Dispatcher.beforeDispatch'),
                    SpanAssertion::exists('cakephp.event', 'Controller.initialize'),
                    SpanAssertion::exists('cakephp.event', 'Controller.startup'),
                    SpanAssertion::exists('cakephp.event', 'Controller.shutdown'),
                    SpanAssertion::exists('cakephp.event', 'Dispatcher.afterDispatch'),
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
                        'http.url' => 'http://localhost:9999/simple_view',
                        'http.status_code' => '200',
                        'integration.name' => 'cakephp',
                    ]),

                    SpanAssertion::exists('cakephp.event', 'Dispatcher.beforeDispatch'),
                    SpanAssertion::exists('cakephp.event', 'Controller.initialize'),
                    SpanAssertion::exists('cakephp.event', 'Controller.startup'),
                    SpanAssertion::exists('cakephp.event', 'Controller.beforeRender'),

                    SpanAssertion::build(
                        'cakephp.view',
                        'cakephp_test_app',
                        'web',
                        'SimpleView/index.ctp'
                    )->withExactTags([
                        'cakephp.view' => 'SimpleView/index.ctp',
                        'integration.name' => 'cakephp',
                    ]),

                    SpanAssertion::exists('cakephp.event', 'View.beforeRender'),
                    SpanAssertion::exists('cakephp.event', 'View.beforeRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRender'),

                    SpanAssertion::exists('cakephp.event', 'View.beforeLayout'),

                    SpanAssertion::exists('cakephp.event', 'View.beforeRender'),
                    SpanAssertion::exists('cakephp.event', 'View.beforeRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRender'),

                    SpanAssertion::exists('cakephp.event', 'View.afterLayout'),
                    SpanAssertion::exists('cakephp.event', 'Controller.shutdown'),

                    SpanAssertion::exists('cakephp.event', 'Dispatcher.afterDispatch'),
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
                        'http.url' => 'http://localhost:9999/error',
                        // CakePHP doesn't appear to set the proper error code
                        'http.status_code' => '200',
                        'integration.name' => 'cakephp',
                    ])->withExistingTagsNames([
                        'error.stack'
                    ])->setError(null, 'Foo error'),

                    SpanAssertion::exists('cakephp.event', 'Dispatcher.beforeDispatch'),
                    SpanAssertion::exists('cakephp.event', 'Controller.initialize'),
                    SpanAssertion::exists('cakephp.event', 'Controller.startup'),
                    SpanAssertion::exists('cakephp.event', 'Controller.initialize'),
                    SpanAssertion::exists('cakephp.event', 'Controller.startup'),
                    SpanAssertion::exists('cakephp.event', 'Controller.beforeRender'),

                    SpanAssertion::build(
                        'cakephp.view',
                        'cakephp_test_app',
                        'web',
                        'Errors/index.ctp'
                    )->withExactTags([
                        'cakephp.view' => 'Errors/index.ctp',
                        'integration.name' => 'cakephp',
                    ]),

                    SpanAssertion::exists('cakephp.event', 'View.beforeRender'),
                    SpanAssertion::exists('cakephp.event', 'View.beforeRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.beforeRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRender'),

                    SpanAssertion::exists('cakephp.event', 'View.beforeLayout'),

                    SpanAssertion::exists('cakephp.event', 'View.beforeRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.beforeRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRenderFile'),
                    SpanAssertion::exists('cakephp.event', 'View.afterRenderFile'),

                    SpanAssertion::exists('cakephp.event', 'View.afterLayout'),
                    SpanAssertion::exists('cakephp.event', 'Controller.shutdown'),

                    SpanAssertion::exists('cakephp.event', 'Dispatcher.afterDispatch'),
                ],
            ]
        );
    }
}
