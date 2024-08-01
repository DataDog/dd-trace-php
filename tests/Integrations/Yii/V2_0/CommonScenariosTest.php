<?php

namespace DDTrace\Tests\Integrations\Yii\V2_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Type;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Yii/Version_2_0/web/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'yii2_test_app',
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
                        'yii2_test_app',
                        'web',
                        'GET /simple'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/simple?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'app\controllers\SimpleController::actionIndex',
                        'app.route.path' => '/simple',
                        Tag::HTTP_ROUTE => '/simple',
                        Tag::SPAN_KIND => "server",
                        Tag::COMPONENT => "yii",
                    ])->withChildren([
                        SpanAssertion::build(
                            'yii\web\Application.run',
                            'yii2_test_app',
                            Type::WEB_SERVLET,
                            'yii\web\Application.run'
                        )->withExactTags([
                            Tag::COMPONENT => "yii",
                        ])->withChildren([
                            SpanAssertion::build(
                                'yii\web\Application.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'simple/index'
                            )->withExactTags([
                                Tag::COMPONENT => "yii",
                            ])->withChildren([
                                SpanAssertion::build(
                                    'app\controllers\SimpleController.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'index'
                                )->withExactTags([
                                    Tag::COMPONENT => "yii",
                                ]),
                            ]),
                        ]),
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'web.request',
                        'yii2_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/simple_view?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'app\controllers\SimpleController::actionView',
                        'app.route.path' => '/simple_view',
                        Tag::HTTP_ROUTE => '/simple_view',
                        Tag::SPAN_KIND => "server",
                        Tag::COMPONENT => "yii",
                    ])->withChildren([
                        SpanAssertion::build(
                            'yii\web\Application.run',
                            'yii2_test_app',
                            Type::WEB_SERVLET,
                            'yii\web\Application.run'
                        )->withExactTags([
                            Tag::COMPONENT => "yii",
                        ])->withChildren([
                            SpanAssertion::build(
                                'yii\web\Application.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'simple/view'
                            )->withExactTags([
                                Tag::COMPONENT => "yii",
                            ])->withChildren([
                                SpanAssertion::build(
                                    'app\controllers\SimpleController.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'view'
                                )->withExactTags([
                                    Tag::COMPONENT => "yii",
                                ])->withChildren([
                                    SpanAssertion::exists('yii\web\View.renderFile'),
                                    SpanAssertion::exists('yii\web\View.renderFile'),
                                ]),
                            ]),
                        ]),
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'web.request',
                        'yii2_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/error?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE => '500',
                        'app.endpoint' => 'app\controllers\SimpleController::actionError',
                        'app.route.path' => '/error',
                        Tag::HTTP_ROUTE => '/error',
                        Tag::SPAN_KIND => "server",
                        Tag::COMPONENT => "yii",
                    ])
                        ->setError(
                            'Exception',
                            'datadog',
                            true
                        )
                        ->withChildren([
                            SpanAssertion::build(
                                'yii\web\Application.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'site/error'
                            )->withExactTags([
                                Tag::COMPONENT => "yii",
                            ])->withChildren([
                                SpanAssertion::build(
                                    'app\controllers\SiteController.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'error'
                                )->withExactTags([
                                    Tag::COMPONENT => "yii",
                                ])->withChildren([
                                    SpanAssertion::exists('yii\web\View.renderFile'),
                                    SpanAssertion::exists('yii\web\View.renderFile'),
                                ]),
                            ]),
                            SpanAssertion::build(
                                'yii\web\Application.run',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'yii\web\Application.run'
                            )->withExactTags([
                                Tag::COMPONENT => "yii",
                            ])->setError('Exception', 'datadog', true)
                                ->withChildren([
                                SpanAssertion::build(
                                    'yii\web\Application.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'simple/error'
                                )->withExactTags([
                                    Tag::COMPONENT => "yii",
                                ])->setError('Exception', 'datadog', true)
                                    ->withChildren([
                                        SpanAssertion::build(
                                            'app\controllers\SimpleController.runAction',
                                            'yii2_test_app',
                                            Type::WEB_SERVLET,
                                            'error'
                                        )->withExactTags([
                                            Tag::COMPONENT => "yii",
                                        ])->setError('Exception', 'datadog', true),
                                    ]),
                                ]),
                        ]),
                ],
                'A GET request to a route with a parameter' => [
                    SpanAssertion::build(
                        'web.request',
                        'yii2_test_app',
                        'web',
                        'GET /parameterized/?'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/parameterized/paramValue',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'app\controllers\SimpleController::actionParameterized',
                        'app.route.path' => '/parameterized/:value',
                        Tag::HTTP_ROUTE => '/parameterized/:value',
                        Tag::SPAN_KIND => "server",
                        Tag::COMPONENT => "yii",
                    ])->withChildren([
                        SpanAssertion::build(
                            'yii\web\Application.run',
                            'yii2_test_app',
                            Type::WEB_SERVLET,
                            'yii\web\Application.run'
                        )->withExactTags([
                            Tag::COMPONENT => "yii",
                        ])->withChildren([
                            SpanAssertion::build(
                                'yii\web\Application.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'simple/parameterized'
                            )->withExactTags([
                                Tag::COMPONENT => "yii",
                            ])->withChildren([
                                SpanAssertion::build(
                                    'app\controllers\SimpleController.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'parameterized'
                                )->withExactTags([
                                    Tag::COMPONENT => "yii",
                                ]),
                            ]),
                        ]),
                    ]),
                ],
                ]
            );
    }
}
