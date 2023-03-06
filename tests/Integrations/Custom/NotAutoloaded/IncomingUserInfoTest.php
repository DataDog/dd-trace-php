<?php

namespace DDTrace\Tests\Integrations\Custom\NotAutoloaded;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;

final class IncomingUserInfoTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Not_Autoloaded/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'my-service',
        ]);
    }

    public function testSelectedHeadersAreIncluded()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->sendRequest('GET', self::HOST_WITH_CREDENTIALS . ':' . self::PORT);
        });

        $tags = [
            'http.method' => 'GET',
            'http.url' => 'http://localhost:' . self::PORT . '/',
            'http.status_code' => 200,
        ];

        if (PHP_MAJOR_VERSION >= 8) {
            $tags[Tag::COMPONENT] = 'lumen';
            $tags[Tag::SPAN_KIND] = 'server';
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
        $this->markTestIncomplete(
            "Tag 'http.url' should include obfuscated '?:?@' user information"
        );
    }
}
