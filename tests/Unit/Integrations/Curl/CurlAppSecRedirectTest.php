<?php

namespace DDTrace\Tests\Unit\Integrations\Curl;

use DDTrace\Integrations\Curl\CurlHandleAppSecContext;
use DDTrace\Tests\Common\BaseTestCase;

require_once __DIR__ . '/../../../../src/DDTrace/Integrations/Curl/CurlAppSec.php';

/**
 * The AppSec curl integration resolves the Location of redirects itself, so
 * that the WAF sees the URL curl is about to request (server.io.net.url).
 */
final class CurlAppSecRedirectTest extends BaseTestCase
{
    /**
     * @dataProvider dataProviderResolveRelativeUrl
     */
    public function testResolveRelativeUrl($base, $location, $expected)
    {
        $class = new \ReflectionClass(CurlHandleAppSecContext::class);
        $ctx = $class->newInstanceWithoutConstructor();

        $url = $class->getProperty('url');
        $url->setAccessible(true);
        $url->setValue($ctx, $base);

        $resolve = $class->getMethod('resolveRelativeUrl');
        $resolve->setAccessible(true);

        $this->assertSame($expected, $resolve->invoke($ctx, $location));
    }

    public function dataProviderResolveRelativeUrl()
    {
        return [
            'absolute url' => ['http://h/a', 'https://o/p?q', 'https://o/p?q'],
            'scheme-relative url' => ['https://h/a', '//o/p', 'https://o/p'],
            'absolute path' => ['http://h/a/b?x=1', '/c?q=1', 'http://h/c?q=1'],
            'relative path' => ['http://h/a/b?x=1', 'c', 'http://h/a/c'],
            'relative path with dot segments' => ['http://h/a/b/c', '../d/./e', 'http://h/a/d/e'],
            'dot segments above root' => ['http://h/a', '../../d', 'http://h/d'],
            // RFC 3986, 5.2.2: a query-only reference keeps the base path
            'query only' => ['http://h/a/b?x=1', '?y=2', 'http://h/a/b?y=2'],
            'query only, base without path' => ['http://h', '?y=2', 'http://h/?y=2'],
            'port and credentials are kept' => ['http://u:p@h:81/a', '/z', 'http://u:p@h:81/z'],
            'user without password' => ['http://u@h/a', '/z', 'http://u@h/z'],
            // RFC 3986, 5.2.2: an empty reference is the base itself
            'empty location' => ['http://h/a?x', '', 'http://h/a?x'],
        ];
    }
}
