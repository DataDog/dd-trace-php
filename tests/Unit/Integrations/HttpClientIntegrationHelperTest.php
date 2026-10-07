<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Integrations\HttpClientIntegrationHelper;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;

final class HttpClientIntegrationHelperTest extends BaseTestCase
{
    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_HTTP_CLIENT_ERROR_STATUSES',
        ];
    }

    private static function newSpan()
    {
        $span = new \stdClass();
        $span->meta = [];
        return $span;
    }

    /**
     * @dataProvider dataProviderIsClientError
     */
    public function testIsClientError($config, $statusCode, $expected)
    {
        self::putEnvAndReloadConfig($config === null ? [] : ["DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=$config"]);
        $this->assertSame($expected, HttpClientIntegrationHelper::isClientError($statusCode));
    }

    public function dataProviderIsClientError()
    {
        return [
            // default: 400-499
            [null, 200, false],
            [null, 399, false],
            [null, 400, true],
            [null, 404, true],
            [null, 499, true],
            [null, 500, false],

            // single codes and ranges
            ['403,408-417', 200, false],
            ['403,408-417', 403, true],
            ['403,408-417', 404, false],
            ['403,408-417', 408, true],
            ['403,408-417', 412, true],
            ['403,408-417', 417, true],
            ['403,408-417', 418, false],
            ['403,408-417', 500, false],

            // empty configuration disables client errors
            ['', 404, false],
            ['', 500, false],
        ];
    }

    public function testSetClientError()
    {
        self::putEnvAndReloadConfig(['DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=403,408-417']);

        $span = self::newSpan();
        $this->assertTrue(HttpClientIntegrationHelper::setClientError($span, 403));
        $this->assertSame('http_error', $span->meta[Tag::ERROR_TYPE]);
        $this->assertSame('HTTP 403 Error', $span->meta[Tag::ERROR_MSG]);

        $span = self::newSpan();
        $this->assertTrue(HttpClientIntegrationHelper::setClientError($span, 412, 'Precondition Failed'));
        $this->assertSame('HTTP 412: Precondition Failed', $span->meta[Tag::ERROR_MSG]);

        $span = self::newSpan();
        $this->assertFalse(HttpClientIntegrationHelper::setClientError($span, 200));
        $this->assertSame([], $span->meta);
    }

    public function testSetClientErrorKeepsExistingError()
    {
        self::putEnvAndReloadConfig(['DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=403']);

        $span = self::newSpan();
        $span->meta[Tag::ERROR] = 1;
        $this->assertFalse(HttpClientIntegrationHelper::setClientError($span, 403));
        $this->assertArrayNotHasKey(Tag::ERROR_TYPE, $span->meta);
    }

    public function testSetClientErrorWithEmptyConfiguration()
    {
        self::putEnvAndReloadConfig(['DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=']);

        $span = self::newSpan();
        $this->assertFalse(HttpClientIntegrationHelper::setClientError($span, 404));
        $this->assertSame([], $span->meta);
    }
}
