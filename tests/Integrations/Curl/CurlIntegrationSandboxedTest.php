<?php

namespace DDTrace\Tests\Integrations\Curl;

final class CurlIntegrationSandboxedTest extends CurlIntegrationTest
{
    const IS_SANDBOX = true;

    public function testKVStoreIsCleanedOnCurlClose()
    {
        $this->markTestSkipped('ArrayKVStore is not used in sandbox API');
    }
}
