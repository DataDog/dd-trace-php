<?php

namespace DDTrace\Tests\Integrations\Yii\V2_0_26;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Type;

class LazyLoadingIntegrationsFromYiiTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Yii/Version_2_0/web/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'yii2_test_app',
            'DD_TRACE_DEBUG' => true,
        ]);
    }

    public function testPdo()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('pdo', '/pdo');
            $response = $this->call($spec);
            error_log('Response: ' . var_export($response, 1));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'yii2_test_app',
                    Type::WEB_SERVLET,
                    'GET /pdo/pdo'
                )->withExactTags([
                    Tag::HTTP_METHOD => 'GET',
                    Tag::HTTP_URL => 'http://localhost:9999/pdo/index',
                    Tag::HTTP_STATUS_CODE => '200',
                    'app.route.path' => '/pdo/pdo',
                    'app.endpoint' => 'app\controllers\PdoController::actionIndex',
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
                            'pdo'
                        )->withChildren([
                            SpanAssertion::build(
                                'app\controllers\PdoController.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'index'
                            )->withChildren([
                                SpanAssertion::exists('PDO.__construct'),
                                SpanAssertion::exists('PDO.prepare'),
                                SpanAssertion::exists('PDOStatement.execute'),
                            ]),
                        ])
                    ])
                ])
            ]
        );
    }
}
