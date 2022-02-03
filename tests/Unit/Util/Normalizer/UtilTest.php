<?php

namespace DDTrace\Tests\Unit\Util\Normalizer;

use DDTrace\Tests\Common\BaseTestCase;

class UtilTest extends BaseTestCase
{
    /**
     * @dataProvider dataProviderHostUDSAsServiceNormalization
     */
    public function testDataProviderHostUDSAsServiceNormalization($hostOrUDS, $expected)
    {
        $this->assertSame(\DDTrace\Util\Normalizer::normalizeHostUdsAsService($hostOrUDS), $expected);
    }

    public function dataProviderHostUDSAsServiceNormalization()
    {
        return [
            'empty' => ['', ''],
            'null' => [null, ''],

            'invalid_host' => ['invalid', 'invalid'],
            // This is a best effort, where '.' is valid and all unexpected chars are replaced with -
            'invalid_chars' => ['!in!!v@@@a.l/id', 'in-v-a.l-id'],

            'ip_without_schema' => ['127.0.0.1', '127.0.0.1'],
            'ip_without_schema_trailing_slash' => ['127.0.0.1/', '127.0.0.1'],
            'ip_with_schema' => ['http://127.0.0.1', '127.0.0.1'],
            'ip_with_schema_trailing_slash' => ['http://127.0.0.1/', '127.0.0.1'],

            'underscores_preserved' => ['underscore_in_host_name', 'underscore_in_host_name'],

            'without_schema' => ['example.com', 'example.com'],
            'trim' => ['   example   .com   ', 'example.com'],

            // Do not remove schema extraction, as it is used by predis/phpredis
            // https://github.com/phpredis/phpredis#connect-open
            'schema_http' => ['http://example.com', 'example.com'],
            'schema_https' => ['https://example.com', 'example.com'],
            'schema_tls' => ['tls://example.com', 'example.com'],
            'schema_ftp' => ['ftp://example.com', 'example.com'],

            'uds' => ['/tmp/redis.sock', 'tmp-redis.sock'],
        ];
    }
}
