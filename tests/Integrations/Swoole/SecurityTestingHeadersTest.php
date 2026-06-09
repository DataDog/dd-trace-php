<?php

namespace DDTrace\Tests\Integrations\Swoole;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class SecurityTestingHeadersTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Swoole/index.php';
    }

    protected static function isSwoole()
    {
        return true;
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
        ]);
    }

    protected static function getInis()
    {
        return array_merge(parent::getInis(), [
            'extension' => 'swoole.so',
        ]);
    }

    public function testSecurityTestingHeadersCollectedUnconditionally()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('request', '/', [
                'X-Datadog-Endpoint-Scan: endpoint-scan-uuid',
                'X-Datadog-Security-Test: security-test-uuid',
            ]);
            $this->call($spec);
        });

        $span = $traces[0][0];
        $this->assertSame(
            'endpoint-scan-uuid',
            $span['meta']['http.request.headers.x-datadog-endpoint-scan']
        );
        $this->assertSame(
            'security-test-uuid',
            $span['meta']['http.request.headers.x-datadog-security-test']
        );
    }

    public function testSecurityTestingHeadersAbsentWhenNotSent()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('request', '/'));
        });

        $span = $traces[0][0];
        $this->assertArrayNotHasKey(
            'http.request.headers.x-datadog-endpoint-scan',
            $span['meta']
        );
        $this->assertArrayNotHasKey(
            'http.request.headers.x-datadog-security-test',
            $span['meta']
        );
    }
}
