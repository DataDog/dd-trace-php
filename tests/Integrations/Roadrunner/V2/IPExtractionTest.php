<?php

namespace DDTrace\Tests\Integrations\Roadrunner\V2;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class IPExtractionTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Roadrunner/Version_2/worker.php';
    }

    protected static function getRoadrunnerVersion()
    {
        return "2.11.4";
    }

    protected static function getEnvs()
    {
        return \array_merge(parent::getEnvs(), [
            'DD_TRACE_HEADER_TAGS' => "x-header",
            'DD_TRACE_CLIENT_IP_ENABLED' => "true"
        ]);
    }

    public function testIpExtraction()
    {
        $traces = $this->tracesFromWebRequest(function () use (&$current_context) {
            \DDTrace\add_distributed_tag("user_id", 42);
            \DDTrace\start_span();
            $current_context = \DDTrace\current_context();
            $spec = GetSpec::create('request', '/', [
                "User-Agent: Test",
                "x-header: somevalue",
                'x-forwarded-for: 128.12.34.1',
            ]);
            $this->call($spec);
        });

        $trace = $traces[0][0];
        $this->assertSame($current_context["trace_id"], $trace["trace_id"]);
        $this->assertSame("42", $trace["meta"]["_dd.p.user_id"]);
        $this->assertSame("Test", $trace["meta"]["http.useragent"]);
        $this->assertSame("somevalue", $trace["meta"]["http.request.headers.x-header"]);
        $this->assertSame("128.12.34.1", $trace["meta"]["http.client_ip"]);
    }
}
