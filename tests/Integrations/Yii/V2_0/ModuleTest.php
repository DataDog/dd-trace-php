<?php

namespace DDTrace\Tests\Integrations\Yii\V2_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Type;

class ModuleTest extends WebFrameworkTestCase
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

    public function testGet()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('forum get', '/forum/new-york/new-york/manhattan?key=value&pwd=should_redact');
            $this->call($spec);
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'yii2_test_app',
                    Type::WEB_SERVLET,
                    'GET /forum/?/?/?'
                )->withExactTags([
                    Tag::HTTP_METHOD => 'GET',
                    Tag::HTTP_URL => 'http://localhost/forum/new-york/new-york/manhattan?key=value&<redacted>',
                    Tag::HTTP_STATUS_CODE => '200',
                    'app.route.path' => '/forum/:state/:city/:neighborhood',
                    Tag::HTTP_ROUTE => '/forum/:state/:city/:neighborhood',
                    'app.endpoint' => 'app\modules\forum\controllers\ModuleController::actionView',
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
                            'forum/module/view'
                        )->withExactTags([
                            Tag::COMPONENT => "yii",
                        ])->withChildren([
                            SpanAssertion::build(
                                'app\modules\forum\controllers\ModuleController.runAction',
                                'yii2_test_app',
                                Type::WEB_SERVLET,
                                'view'
                            )->withExactTags([
                                Tag::COMPONENT => "yii",
                            ])
                        ])
                    ])
                ])
            ]
        );
    }
}
