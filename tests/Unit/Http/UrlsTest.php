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
}
