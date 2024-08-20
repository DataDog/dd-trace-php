<?php

namespace DDTrace\Tests\Integrations\Custom\NotAutoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class HttpHeadersConfiguredTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Not_Autoloaded/Headers/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'my-service',
            'DD_TRACE_HEADER_TAGS' => '  fIrSt-HEADER   ,  SECOND-header  , third-HEADER , FORTH-HEADER, W$%rd-header',
        ]);
    }

    public function testSelectedHeadersAreIncluded()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create(
                'First request: Startup logs test',
                '/',
                [
                    'first-Header: some value: with colon',
                    'FORTH-header: 123',
                    'W$%rd-header: foo',
                ]
            );
            $this->call($spec);
        });

        $tags = [
            'http.method' => 'GET',
            'http.url' => 'http://localhost/',
            'http.status_code' => 200,
            'http.request.headers.first-header' => 'some value: with colon',
            'http.request.headers.forth-header' => '123',
            'http.response.headers.third-header' => 'separated: with  : colon',
        ];
        if (\getenv('DD_TRACE_TEST_SAPI') != 'apache2handler') {
            $tags['http.request.headers.w__rd-header'] = 'foo';
        }

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'my-service',
                    'web',
                    'GET /'
                )->withExactTags($tags),
            ]
        );
    }
}
