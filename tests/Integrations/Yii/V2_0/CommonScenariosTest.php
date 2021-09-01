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
                        Tag::HTTP_URL => 'http://localhost:9999/simple',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'app\controllers\SimpleController::actionIndex',
                        'app.route.path' => '/simple',
                    ])->withChildren([
                        SpanAssertion::build(
                            'yii\web\Application.run',
                            'yii2_test_app',
                            Type::WEB_SERVLET,
                            'yii\web\Application.run'
                        )->withChildren([
                            SpanAssertion::build(
                                'yii\web\Application.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'simple/index'
                            )->withChildren([
                                SpanAssertion::build(
                                    'app\controllers\SimpleController.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'index'
                                ),
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
                        Tag::HTTP_URL => 'http://localhost:9999/simple_view',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'app\controllers\SimpleController::actionView',
                        'app.route.path' => '/simple_view',
                    ])->withChildren([
                        SpanAssertion::build(
                            'yii\web\Application.run',
                            'yii2_test_app',
                            Type::WEB_SERVLET,
                            'yii\web\Application.run'
                        )->withChildren([
                            SpanAssertion::build(
                                'yii\web\Application.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'simple/view'
                            )->withChildren([
                                SpanAssertion::build(
                                    'app\controllers\SimpleController.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'view'
                                )->withChildren([
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
                        Tag::HTTP_URL => 'http://localhost:9999/error',
                        Tag::HTTP_STATUS_CODE => '500',
                        'app.endpoint' => 'app\controllers\SimpleController::actionError',
                        'app.route.path' => '/error',
                    ])
                        ->setError(
                            PHP_VERSION_ID >= 70000 ? 'Exception' : null,
                            PHP_VERSION_ID >= 70000 ? 'datadog' : null,
                            PHP_VERSION_ID >= 70000
                        )
                        ->withChildren([
                            SpanAssertion::build(
                                'yii\web\Application.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'site/error'
                            )->withChildren([
                                SpanAssertion::build(
                                    'app\controllers\SiteController.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'error'
                                )->withChildren([
                                    SpanAssertion::exists('yii\web\View.renderFile'),
                                    SpanAssertion::exists('yii\web\View.renderFile'),
                                ]),
                            ]),
                            SpanAssertion::build(
                                'yii\web\Application.run',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'yii\web\Application.run'
                            )->setError('Exception', 'datadog', true)
                                ->withChildren([
                                SpanAssertion::build(
                                    'yii\web\Application.runAction',
                                    'yii2_test_app',
                                    Type::WEB_SERVLET,
                                    'simple/error'
                                )->setError('Exception', 'datadog', true)
                                    ->withChildren([
                                        SpanAssertion::build(
                                            'app\controllers\SimpleController.runAction',
                                            'yii2_test_app',
                                            Type::WEB_SERVLET,
                                            'error'
                                        )->setError('Exception', 'datadog', true),
                                    ]),
                                ]),
                        ]),
                ],
                ]
            );
    }
}
