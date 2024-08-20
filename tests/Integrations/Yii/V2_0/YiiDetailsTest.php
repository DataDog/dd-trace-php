<?php

namespace DDTrace\Tests\Integrations\Yii\V2_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
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

    public function testRootIndexRoute()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('root', '/');
            $this->call($spec);
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'yii2_test_app',
                    Type::WEB_SERVLET,
                    'GET /site/index'
                )->withExactTags([
                    Tag::HTTP_METHOD => 'GET',
                    Tag::HTTP_URL => 'http://localhost/site/index',
                    Tag::HTTP_STATUS_CODE => '200',
                    'app.route.path' => '/site/index',
                    Tag::HTTP_ROUTE => '/site/index',
                    'app.endpoint' => 'app\controllers\SiteController::actionIndex',
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
                            'index'
                        )->withExactTags([
                            Tag::COMPONENT => "yii",
                        ])->withChildren([
                            SpanAssertion::build(
                                'app\controllers\SiteController.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'index'
                            )->withExactTags([
                                Tag::COMPONENT => "yii",
                            ]),
                        ]),
                    ])
                ])
            ]
        );
    }
}
