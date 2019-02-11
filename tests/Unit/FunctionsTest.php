<?php

namespace
{
    require __DIR__ . '/../../bridge/functions.php';
}

namespace DDTrace\Tests\Unit
{
    final class FunctionsTest extends BaseTestCase
    {
        protected function setUp()
        {
            parent::setUp();
            putenv('DD_TRACE_ENABLED');
            putenv('DD_DISABLE_URI');
        }

        public function testTracerEnabledByDefault()
        {
            $this->assertTrue(\DDTrace\Bridge\dd_tracing_enabled());
        }

        public function testTracerDisabled()
        {
            putenv('DD_TRACE_ENABLED=false');
            $this->assertFalse(\DDTrace\Bridge\dd_tracing_enabled());
        }

        public function testTracerEnabledForUrisByDefault()
        {
            $this->assertTrue(\DDTrace\Bridge\dd_tracing_route_enabled());
        }

        /**
         * @dataProvider urisDataProvider
         * @param string $uri
         * @param bool $expected
         */
        public function testTracerEnabledOrDisabledForUris($uri, $expected)
        {
            putenv('DD_DISABLE_URI=/foo,/users/*,/bar/*/test,/index.php?foo=*');

            $this->assertSame(
                $expected,
                \DDTrace\Bridge\dd_tracing_route_enabled($uri)
            );
        }

        public function urisDataProvider()
        {
            return [
                ['', true],
                ['/', true],
                ['/foo', false],
                ['/users', true],
                ['/users/' . mt_rand(), false],
                ['/bar/test', true],
                ['/bar/' . mt_rand() . '/more/test', false],
                ['/index.php?foo=' . mt_rand(), false],
            ];
        }
    }
}
