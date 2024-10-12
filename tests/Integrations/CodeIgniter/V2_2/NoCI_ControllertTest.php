<?php

namespace DDTrace\Tests\Integrations\CodeIgniter\V2_2;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Type;

class NoCIControllertTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CodeIgniter/Version_2_2/ddshim.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'codeigniter_test_app',
        ]);
    }

    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('A call to healthcheck', '/health_check/ping'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'codeigniter.request',
                    'codeigniter_test_app',
                    'web',
                    'GET /health_check/ping'
                )->withExactTags([
                    Tag::HTTP_METHOD => 'GET',
                    Tag::HTTP_URL => 'http://localhost/health_check/ping',
                    Tag::HTTP_STATUS_CODE => '200',
                    Tag::SPAN_KIND => 'server',
                    Tag::COMPONENT => 'codeigniter',
                    Tag::HTTP_ROUTE => 'health_check/ping',
                ])->withChildren([
                    SpanAssertion::build(
                        'Health_check.ping',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'Health_check.ping'
                    )->withExactTags([
                        Tag::COMPONENT => 'codeigniter',
                    ])
                ]),
            ]
        );
    }
}
