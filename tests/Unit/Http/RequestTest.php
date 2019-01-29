<?php

namespace DDTrace\Tests\Unit\Http;

use DDTrace\Http\Request;
use PHPUnit\Framework;

final class RequestTest extends Framework\TestCase
{
    public function testRequestHeadersCanBeExtracted()
    {
        $headers = Request::getHeaders([
            'HTTP_HOST' => 'localhost:8888',
            'HTTP_USER_AGENT' => 'Foo test',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'HTTP_X_DATADOG_TRACE_ID' => '1234',
        ]);

        $this->assertSame([
            'host' => 'localhost:8888',
            'user-agent' => 'Foo test',
            'accept-language' => 'en-US,en;q=0.9',
            'x-datadog-trace-id' => '1234',
        ], $headers);
    }
}
