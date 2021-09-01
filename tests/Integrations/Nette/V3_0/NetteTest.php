<?php

namespace DDTrace\Tests\Integrations\Nette\V3_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Type;

final class NetteTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Nette/Version_3_0/www/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'nette_test_app',
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
        //$this->assertExpectedSpans($traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'web.request',
                        'nette_test_app',
                        'web',
                        'GET /simple'
                    )->withExactTags([
                        'nette.route.presenter' => 'Homepage',
                        'nette.route.action' => 'simple',
                        'http.method' => 'GET',
                        'http.url' => '/simple',
                        'http.status_code' => '200',
                    ])->withChildren([
                        SpanAssertion::build(
                            'nette.configurator.createRobotLoader',
                            'nette_test_app',
                            Type::WEB_SERVLET,
                            'nette.configurator.createRobotLoader'
                        ),
                        SpanAssertion::build(
                            'nette.application.run',
                            'nette_test_app',
                            Type::WEB_SERVLET,
                            'nette.application.run'
                        )->withChildren([
                            SpanAssertion::build(
                                'nette.presenter.run',
                                'nette_test_app',
                                Type::WEB_SERVLET,
                                'nette.presenter.run'
                            )
                        ])
                    ])
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'web.request',
                        'nette_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        'nette.route.presenter' => 'Homepage',
                        'nette.route.action' => 'simpleView',
                        'http.method' => 'GET',
                        'http.url' => '/simple_view',
                        'http.status_code' => '200',
                    ])->withChildren([
                        SpanAssertion::build(
                            'nette.configurator.createRobotLoader',
                            'nette_test_app',
                            Type::WEB_SERVLET,
                            'nette.configurator.createRobotLoader'
                        ),
                        SpanAssertion::build(
                            'nette.application.run',
                            'nette_test_app',
                            Type::WEB_SERVLET,
                            'nette.application.run'
                        )->withChildren([
                            SpanAssertion::build(
                                'nette.presenter.run',
                                'nette_test_app',
                                Type::WEB_SERVLET,
                                'nette.presenter.run'
                            ),
                            SpanAssertion::build(
                                'nette.latte.render',
                                'nette_test_app',
                                Type::WEB_SERVLET,
                                'nette.latte.render'
                            )->withExactTags([
                                'nette.latte.templateName' => '%s'
                            ])->withChildren([
                                SpanAssertion::exists('nette.latte.createTemplate'), // layout template
                                SpanAssertion::exists('nette.latte.createTemplate'), // simpleView template
                            ])
                        ])
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'web.request',
                        'nette_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        'nette.route.presenter' => 'Homepage',
                        'nette.route.action' => 'errorView',
                        'http.method' => 'GET',
                        'http.url' => '/error',
                        'http.status_code' => '500',
                    ])
                    ->setError(
                        PHP_VERSION_ID >= 70000 ? 'Exception' : 'Internal Server Error',
                        PHP_VERSION_ID >= 70000 ? 'An exception occurred' : null,
                        PHP_VERSION_ID >= 70000
                    )
                    ->withChildren([
                        SpanAssertion::build(
                            'nette.configurator.createRobotLoader',
                            'nette_test_app',
                            Type::WEB_SERVLET,
                            'nette.configurator.createRobotLoader'
                        ),
                        SpanAssertion::build(
                            'nette.application.run',
                            'nette_test_app',
                            Type::WEB_SERVLET,
                            'nette.application.run'
                        )->withChildren([
                            SpanAssertion::build(
                                'nette.presenter.run',
                                'nette_test_app',
                                Type::WEB_SERVLET,
                                'nette.presenter.run'
                            )
                            ->setError('Exception', 'An exception occurred')
                            ->withExistingTagsNames(['error.stack']),
                        ])
                    ])
                ],
            ]
        );
    }
}
