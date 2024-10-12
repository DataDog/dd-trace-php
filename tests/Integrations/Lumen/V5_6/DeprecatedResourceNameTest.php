<?php

namespace DDTrace\Tests\Integrations\Lumen\V5_6;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class DeprecatedResourceNameTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Lumen/Version_5_6/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => 'false',
        ]);
    }

    /**
     * @throws \Exception
     */
    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing the legacy way to name resources after the controller', '/simple'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'lumen.request',
                    'lumen',
                    'web',
                    'GET simple_route'
                )
                    ->withExactTags([
                        'lumen.route.name' => 'simple_route',
                        'lumen.route.action' => 'App\Http\Controllers\ExampleController@simple',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'lumen',
                    ])
                    ->withChildren([
                        SpanAssertion::build(
                            'Laravel\Lumen\Application.handleFoundRoute',
                            'lumen',
                            'web',
                            'simple_route'
                        )->withExactTags([
                            'lumen.route.action' => 'App\Http\Controllers\ExampleController@simple',
                            Tag::COMPONENT => 'lumen',
                        ]),
                    ]),
            ]
        );
    }
}
