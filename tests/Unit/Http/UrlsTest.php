<?php

namespace DDTrace\Tests\Unit\Http;

use DDTrace\Http\Urls;
use DDTrace\Tests\Common\BaseTestCase;

final class UrlsTest extends BaseTestCase
{
    /**
     * @dataProvider dataProviderSanitize
     * @param string $url
     * @param string $expected
     * @return void
     */
    public function testSanitize($url, $expected)
    {
        /* This test is an exact replica of the same method in tests/Unit/private/UriTest.php and has to be kept in sync
         * until the other test will be removed as part of the PHP->C migration
         */
        $this->assertSame($expected, Urls::sanitize($url));
    }

    public function dataProviderSanitize()
    {
        return [
            // empty
            [null, ''],
            ['', ''],

            // with schema
            ['https://some_url.com/path/', 'https://some_url.com/path/'],

            // with no schema
            ['some_url.com/path/', 'some_url.com/path/'],

            // query and fragment
            ['some_url.com/path/?some=value', 'some_url.com/path/'],
            ['some_url.com/path/?some=value#fragment', 'some_url.com/path/'],

            // userinfo
            ['my_user:my_password@some_url.com/path/', '?:?@some_url.com/path/'],
            ['my_user:@some_url.com/path/', '?:@some_url.com/path/'],
            ['my_user:@some_url.com/path/?key=value', '?:@some_url.com/path/'],
            ['https://my_user:my_password@some_url.com/path/', 'https://?:?@some_url.com/path/'],
            ['https://my_user:@some_url.com/path/', 'https://?:@some_url.com/path/'],
            ['https://my_user:@some_url.com/path/?key=value', 'https://?:@some_url.com/path/'],

            // idempotency
            ['https://?:@some_url.com/path/?key=value', 'https://?:@some_url.com/path/'],
            ['?:?@some_url.com/path/', '?:?@some_url.com/path/'],
            ['?:@some_url.com/path/?some=?#fragment', '?:@some_url.com/path/'],

            // false positives that should not be sanitized, but we accept this lack of correctness to reduce complexity
            ['https://my_user:@some_url.com/before/a:b@/after', 'https://?:@some_url.com/before/?:?@/after'],
            [
                'https://my_user:my_passwords@some_url.com/before/a:b@/after',
                'https://?:?@some_url.com/before/?:?@/after',
            ],
        ];
    }

    /**
     * @dataProvider dataProviderSanitizeDropUserinfo
     * @param string $url
     * @param string $expected
     * @return void
     */
    public function testSanitizeDropUserinfo($url, $expected)
    {
        /* This test is an exact replica of the same method in tests/Unit/private/UriTest.php and has to be kept in sync
         * until the other test will be removed as part of the PHP->C migration
         */
        $this->assertSame($expected, Urls::sanitize($url, true));
    }

    public function dataProviderSanitizeDropUserinfo()
    {
        return [
            // empty
            [null, ''],
            ['', ''],

            // with schema
            ['https://some_url.com/path/', 'https://some_url.com/path/'],

            // with no schema
            ['some_url.com/path/', 'some_url.com/path/'],

            // query and fragment
            ['some_url.com/path/?some=value', 'some_url.com/path/'],
            ['some_url.com/path/?some=value#fragment', 'some_url.com/path/'],

            // userinfo
            ['my_user:my_password@some_url.com/path/', 'some_url.com/path/'],
            ['my_user:@some_url.com/path/', 'some_url.com/path/'],
            ['my_user:@some_url.com/path/?key=value', 'some_url.com/path/'],
            ['https://my_user:my_password@some_url.com/path/', 'https://some_url.com/path/'],
            ['https://my_user:@some_url.com/path/', 'https://some_url.com/path/'],
            ['https://my_user:@some_url.com/path/?key=value', 'https://some_url.com/path/'],

            // idempotency
            ['https://?:@some_url.com/path/?key=value', 'https://some_url.com/path/'],
            ['?:?@some_url.com/path/', 'some_url.com/path/'],
            ['?:@some_url.com/path/?some=?#fragment', 'some_url.com/path/'],

            // false positives that should not be sanitized, but we accept this lack of correctness to reduce complexity
            ['https://my_user:@some_url.com/before/a:b@/after', 'https://some_url.com/before//after'],
            [
                'https://my_user:my_passwords@some_url.com/before//after',
                'https://some_url.com/before//after',
            ],
        ];
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

    /**
     * @dataProvider dataProviderHostname
     * @param string $url
     * @param string $expected
     * @return void
     */
    public function testHostname($url, $expected)
    {
        $this->assertSame($expected, Urls::hostname($url));
    }

    public function dataProviderHostname()
    {
        return [
            [null, 'unparsable-host'],
            ['', 'unparsable-host'],

            // no schema
            ['example.com', 'example.com'],
            ['example.com/', 'example.com'],
            ['example.com/path', 'example.com'],
            ['example.com/path?key=value', 'example.com'],
            ['example.com/path?key=value#fragment', 'example.com'],

            // with schema
            ['http://example.com', 'example.com'],
            ['http://example.com/', 'example.com'],
            ['http://example.com/path', 'example.com'],
            ['http://example.com/path?key=value', 'example.com'],
            ['http://example.com/path?key=value#fragment', 'example.com'],

            // no dots in host name
            ['no_dots_in_host', 'no_dots_in_host'],
            ['http://no_dots_in_host', 'no_dots_in_host'],
            ['http://no_dots_in_host/', 'no_dots_in_host'],
            ['http://no_dots_in_host/path', 'no_dots_in_host'],
            ['http://no_dots_in_host/path?key=value', 'no_dots_in_host'],
            ['http://no_dots_in_host/path?key=value#fragment', 'no_dots_in_host'],

            // absolute paths
            ['/', 'unknown-host'],
            ['/path', 'unknown-host'],
            ['/path?key=value', 'unknown-host'],
            ['/path?key=value#fragment', 'unknown-host'],

            // userinfo
            ['my_user:my_password@some_url.com/path/', 'some_url.com'],
            ['my_user:@some_url.com/path/', 'some_url.com'],
            ['https://my_user:my_password@some_url.com/path/', 'some_url.com'],
            ['https://my_user:@some_url.com/path/', 'some_url.com'],

            // uds-style sockets should not generate an error but be converted to unparsable-host,
            // as there is now a dedicated function for them.
            ['uds:///tmp/socket.file', 'unparsable-host'],
            ['http+unix:///tmp/socket.file', 'unparsable-host'],
        ];
    }

    /**
     * @dataProvider dataProviderHostnameForTag
     * @param string $url
     * @param string $expected
     * @return void
     */
    public function testHostnameForTag($url, $expected)
    {
        $this->assertSame($expected, Urls::hostnameForTag($url));
    }

    public function dataProviderHostnameForTag()
    {
        return [
            [null, 'host-unparsable-host'],
            ['', 'host-unparsable-host'],

            // no schema
            ['example.com', 'host-example.com'],
            ['example.com/', 'host-example.com'],
            ['example.com/path', 'host-example.com'],
            ['example.com/path?key=value', 'host-example.com'],
            ['example.com/path?key=value#fragment', 'host-example.com'],

            // with schema
            ['http://example.com', 'host-example.com'],
            ['http://example.com/', 'host-example.com'],
            ['http://example.com/path', 'host-example.com'],
            ['http://example.com/path?key=value', 'host-example.com'],
            ['http://example.com/path?key=value#fragment', 'host-example.com'],

            // no dots in host name
            ['no_dots_in_host', 'host-no_dots_in_host'],
            ['http://no_dots_in_host', 'host-no_dots_in_host'],
            ['http://no_dots_in_host/', 'host-no_dots_in_host'],
            ['http://no_dots_in_host/path', 'host-no_dots_in_host'],
            ['http://no_dots_in_host/path?key=value', 'host-no_dots_in_host'],
            ['http://no_dots_in_host/path?key=value#fragment', 'host-no_dots_in_host'],

            // absolute paths
            ['/', 'host-unknown-host'],
            ['/path', 'host-unknown-host'],
            ['/path?key=value', 'host-unknown-host'],
            ['/path?key=value#fragment', 'host-unknown-host'],

            // Common UDS urls
            ['uds:///tmp/socket.file', 'socket-tmp-socket.file'],
            ['unix:///tmp/socket.file', 'socket-tmp-socket.file'],
            ['http+unix:///tmp/socket.file', 'socket-tmp-socket.file'],
            ['https+unix:///tmp/socket.file', 'socket-tmp-socket.file'],
            ['https+unix:///tmp/s!o!c!k!e!t.file', 'socket-tmp-s-o-c-k-e-t.file'],
            ['https+unix:///   tmp/soc   ket.file    ', 'socket-tmp-soc-ket.file'],
        ];
    }
}
