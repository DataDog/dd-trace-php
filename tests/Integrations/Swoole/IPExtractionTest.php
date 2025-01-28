<?php

namespace DDTrace\Tests\Integrations\Swoole;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class IPExtractionTest extends WebFrameworkTestCase
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
        return \array_merge(parent::getEnvs(), [
            'DD_TRACE_HEADER_TAGS' => "x-header",
            'DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED' => 'foo.password, bar',
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_RESOURCE_URI_QUERY_PARAM_ALLOWED' => '*',
            'DD_TRACE_CLIENT_IP_ENABLED' => "true",
        ]);
    }

    protected static function getInis()
    {
        return array_merge(parent::getInis(), [
            'extension' => 'swoole.so',
        ]);
    }

    public function testIpExtraction()
    {
        $traces = $this->tracesFromWebRequest(function () use (&$traceId) {
            \DDTrace\add_distributed_tag("user_id", 42);
            \DDTrace\start_span();
            $traceId = \DDTrace\root_span()->id;
            $spec = GetSpec::create('request', '/', [
                "User-Agent: Test",
                "x-header: somevalue",
            ]);
            $this->call($spec);
        });

        $trace = $traces[0][0];
        $this->assertSame($traceId, $trace["trace_id"]);
        $this->assertSame("42", $trace["meta"]["_dd.p.user_id"]);
        $this->assertSame("Test", $trace["meta"]["http.useragent"]);
        $this->assertSame("somevalue", $trace["meta"]["http.request.headers.x-header"]);
        $this->assertSame("127.0.0.1", $trace["meta"]["http.client_ip"]);
    }
}
