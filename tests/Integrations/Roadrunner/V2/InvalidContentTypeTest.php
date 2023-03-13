<?php

namespace DDTrace\Tests\Integrations\Roadrunner\V2;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;

class InvalidContentTypeTest extends WebFrameworkTestCase
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
        return \array_merge(parent::getEnvs(), ['DD_TRACE_HEADER_TAGS' => "x-header",
            'DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED' => '*']);
    }

    public function testContentType()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = PostSpec::create('request', '/', [
                'User-Agent: Test',
                'x-header: somevalue',
                'x_forwarded_for', '127.12.34.1',
                'Content-Type: HiImNotAValidContentType'
            ], "password=should_redact&username=should_not_redact&foo[bar]=should_not_redact");
            $this->call($spec);
        });

        $trace = $traces[0][0];
        $this->assertSame("POST", $trace["meta"]["http.method"]);
        $this->assertArrayNotHasKey("http.request.post.password", $trace["meta"]);
        $this->assertArrayNotHasKey("http.request.post.username", $trace["meta"]);
        $this->assertArrayNotHasKey("http.request.post.foo.bar", $trace["meta"]);
    }
}
