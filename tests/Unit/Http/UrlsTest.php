<?php

namespace DDTrace\Tests\Unit\Http;

use DDTrace\Http\Urls;
use PHPUnit\Framework;

final class UrlsTest extends Framework\TestCase
{
    public function testSimpleUrlsAreReturned()
    {
        $this->assertSame('some_url.com/path/', Urls::sanitize('some_url.com/path/'));
    }

    public function testSimpleUrlsWithSchemaAreReturned()
    {
        $this->assertSame('https://some_url.com/path/', Urls::sanitize('https://some_url.com/path/'));
    }

    public function testQueryStringIsRemoved()
    {
        $this->assertSame('some_url.com/path/', Urls::sanitize('some_url.com/path/?some=value'));
    }

    public function testFragmentIsRemoved()
    {
        $this->assertSame('some_url.com/path/', Urls::sanitize('some_url.com/path/?some=value#fragment'));
    }

    /**
     * @dataProvider urlsWithDefaultRulesDataProvider
     * @param string $url
     * @param string $normalizedUrl
     */
    public function testUrlsAreNormalizedWithDefaultRules($url, $normalizedUrl)
    {
        $normalizer = new Urls();
        $this->assertSame($normalizedUrl, $normalizer->normalize($url));
    }

    public function urlsWithDefaultRulesDataProvider()
    {
        return [
            ['/', '/'],
            ['/foo?q=123', '/foo'],
            ['/foo/123', '/foo/?'],
            ['/foo/123/bar', '/foo/?/bar'],
            ['/foo/a5b30c6b-8795-4b65-8343-4b08ed49e4da/bar', '/foo/?/bar'],
            ['/foo/a5b30c6b87954b6583434b08ed49e4da/bar', '/foo/?/bar'],
            ['/talk/b07bbaaf-speaker', '/talk/b07bbaaf-speaker'], // "-" is not a URL boundary
            ['/v1.0/users/1414', '/v1.0/users/?'],
            ['/city/1337/lexington', '/city/?/lexington'],
            ['/city/1337/london', '/city/?/london'],
            ['/api/v2/widget/42', '/api/v2/widget/?'],
        ];
    }

    /**
     * @dataProvider urlsWithCustomRulesDataProvider
     * @param string $url
     * @param string $normalizedUrl
     */
    public function testUrlsAreNormalizedWithCustomRules($url, $normalizedUrl)
    {
        $normalizer = new Urls([
            '/foo/*/bar',
            '/foo/*',
            '/city/*/$*',
            '/talk/*-speaker',
            '/*/$*/$*/$*/test',
        ]);
        $this->assertSame($normalizedUrl, $normalizer->normalize($url));
    }

    public function urlsWithCustomRulesDataProvider()
    {
        return [
            ['/', '/'],
            ['/foo?q=123', '/foo'],
            ['/foo/super-secret', '/foo/?'],
            ['/foo/hide/me/please/bar', '/foo/?/bar'],
            ['/foo/bar/bar/bar/bar', '/foo/?/bar'],
            ['/foo/foo/foo/bar/bar/bar', '/foo/?/bar'],
            ['/talk/secret-hash-speaker', '/talk/?-speaker'],
            ['/city/zz42/lexington', '/city/?/lexington'],
            ['/city/zz42/london', '/city/?/london'],
            ['/secret/one/two/three/test', '/?/one/two/three/test'],
        ];
    }
}
