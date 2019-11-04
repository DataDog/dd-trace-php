<?php

namespace DDTrace\Tests\Integrations\Yii\V2_0_26;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Type;

class ParameterizedRouteTest extends WebFrameworkTestCase
{

    const IS_SANDBOX = true;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Yii/Version_2_0_26/web/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'yii2_test_app',
        ]);
    }

    public function testGet()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('homes get', '/homes/new-york/new-york/manhattan');
            $this->call($spec);
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'yii2_test_app',
                    Type::WEB_SERVLET,
                    'GET /homes/?/?/?'
                )->withExactTags([
                    Tag::HTTP_METHOD => 'GET',
                    Tag::HTTP_URL => 'http://localhost:9999/homes/new-york/new-york/manhattan',
                    Tag::HTTP_STATUS_CODE => '200',
                    'integration.name' => 'yii',
                    'app.route.path' => '/homes/:state/:city/:neighborhood',
                    'app.endpoint' => 'app\controllers\HomesController::actionView',
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
                            'homes/view'
                        )->withChildren([
                            SpanAssertion::build(
                                'app\controllers\HomesController.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'view'
                            )
                        ])
                    ])
                ])
            ]
        );
    }
}
