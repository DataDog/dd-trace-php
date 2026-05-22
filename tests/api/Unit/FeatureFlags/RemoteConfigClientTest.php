<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\Internal\RemoteConfigClient;
use PHPUnit\Framework\TestCase;

final class RemoteConfigClientTest extends TestCase
{
    public function testReadsNativeConfigStateThroughCallables()
    {
        $client = new RemoteConfigClient(
            function () {
                return true;
            },
            function () {
                return '42';
            }
        );

        $this->assertTrue($client->hasConfig());
        $this->assertSame(42, $client->configVersion());
    }

    public function testUnavailableConfigIsReportedWithoutBlocking()
    {
        $client = new RemoteConfigClient(
            function () {
                return false;
            },
            function () {
                return 0;
            }
        );

        $this->assertFalse($client->hasConfig());
        $this->assertSame(0, $client->configVersion());
    }
}
