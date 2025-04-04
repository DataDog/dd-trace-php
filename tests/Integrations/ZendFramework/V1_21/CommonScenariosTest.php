<?php

namespace DDTrace\Tests\Integrations\ZendFramework\V1_21;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/ZendFramework/Version_1_21/public/index.php';
    }

    public static function getTestedLibrary()
    {
        return 'shardj/zf1-future';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '1.21.4';
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
        $specs = [
            'A simple GET request returning a string' => [
                SpanAssertion::build('zf1.request', 'zf1', 'web', 'simple@index default')
                    ->withExactTags([
                        'zf1.controller' => 'simple',
                        'zf1.action' => 'index',
                        'zf1.route_name' => 'default',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => "server",
                        Tag::COMPONENT => "zendframework",
                    ]),
            ],
            'A simple GET request with a view' => [
                SpanAssertion::build('zf1.request', 'zf1', 'web', 'simple@view my_simple_view_route')
                    ->withExactTags([
                        'zf1.controller' => 'simple',
                        'zf1.action' => 'view',
                        'zf1.route_name' => 'my_simple_view_route',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                        Tag::SPAN_KIND => "server",
                        Tag::COMPONENT => "zendframework",
                    ]),
            ],
            'A GET request with an exception' => [
                SpanAssertion::build('zf1.request', 'zf1', 'web', 'error@error default')
                    ->withExactTags([
                        'zf1.controller' => 'error',
                        'zf1.action' => 'error',
                        'zf1.route_name' => 'default',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        Tag::SPAN_KIND => "server",
                        Tag::COMPONENT => "zendframework",
                    ])
                    ->setError('Exception', 'Controller error', true)
            ],
        ];

        return $this->buildDataProvider($specs);
    }
}
