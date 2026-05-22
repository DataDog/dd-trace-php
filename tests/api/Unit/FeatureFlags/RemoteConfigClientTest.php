<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\RemoteConfigClient;
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

    public function testWaitUntilReadyPollsUntilConfigArrives()
    {
        $attempts = 0;
        $client = new RemoteConfigClient(
            function () use (&$attempts) {
                ++$attempts;
                return $attempts >= 2;
            },
            function () {
                return 0;
            }
        );

        $this->assertTrue($client->waitUntilReady(0.05, 1000));
        $this->assertGreaterThanOrEqual(2, $attempts);
    }

    public function testZeroTimeoutDoesNotBlock()
    {
        $client = new RemoteConfigClient(
            function () {
                return false;
            },
            function () {
                return 0;
            }
        );

        $this->assertFalse($client->waitUntilReady(0));
    }
}
