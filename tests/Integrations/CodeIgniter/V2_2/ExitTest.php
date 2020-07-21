<?php

namespace DDTrace\Tests\Integrations\CodeIgniter\V2_2;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Type;

class ExitTest extends WebFrameworkTestCase
{
    const IS_SANDBOX = true;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CodeIgniter/Version_2_2/ddshim.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'codeigniter_test_app',
        ]);
    }

    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Test that exit works', '/exits'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'codeigniter.request',
                    'codeigniter_test_app',
                    'web',
                    'GET /exits'
                )->withExactTags([
                    Tag::HTTP_METHOD => 'GET',
                    Tag::HTTP_URL => 'http://localhost:9999/exits',
                    Tag::HTTP_STATUS_CODE => '200',
                    'app.endpoint' => 'Exits::index',
                ])->withChildren([
                    SpanAssertion::build(
                        'Exits.index',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'Exits.index'
                    )
                ]),
            ]
        );
    }
}
