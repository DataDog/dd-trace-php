<?php

namespace DDTrace\Tests\Integrations\Roadrunner\V2;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;

class DistributedTracingTest extends WebFrameworkTestCase
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
            'DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED' => 'foo.password, bar']);
    }

    public function testDistributedTracing()
    {
        $traces = $this->tracesFromWebRequest(function () use (&$current_context) {
            \DDTrace\add_distributed_tag("user_id", 42);
            \DDTrace\start_span();
            $current_context = \DDTrace\current_context();
            $spec = GetSpec::create('request', '/', [
                "User-Agent: Test",
                "x-header: somevalue",
                'x_forwarded_for', '127.12.34.1',
            ]);
            $this->call($spec);
        });

        $trace = $traces[0][0];
        $this->assertSame($current_context["trace_id"], $trace["trace_id"]);
        $this->assertSame("42", $trace["meta"]["_dd.p.user_id"]);
        $this->assertSame("Test", $trace["meta"]["http.useragent"]);
        $this->assertSame("somevalue", $trace["meta"]["http.request.headers.x-header"]);
        $this->assertArrayNotHasKey('http.client_ip', $trace["meta"]);
    }

    public function testDistributedTracingPostWithAllowedParams()
    {
        $traces = $this->tracesFromWebRequest(function () use (&$current_context) {
            \DDTrace\add_distributed_tag("user_id", 42);
            \DDTrace\start_span();
            $current_context = \DDTrace\current_context();
            $spec = PostSpec::create('request', '/', [
                'User-Agent: Test',
                'x-header: somevalue',
                'x_forwarded_for', '127.12.34.1',
                'Content-Type: application/json'
            ], [
                'pass word' => 'should_redact',
                'foo' => array('password' => 'should_not_redact'),
                'bar' => array('key1' => 'value1', 'key2' => array('baz' => 'value2', 'password' => 'should_not_redact')),
            ]);
            $this->call($spec);
        });

        $trace = $traces[0][0];
        $this->assertSame($current_context["trace_id"], $trace["trace_id"]);
        $this->assertSame("42", $trace["meta"]["_dd.p.user_id"]);
        $this->assertSame("Test", $trace["meta"]["http.useragent"]);
        $this->assertSame("somevalue", $trace["meta"]["http.request.headers.x-header"]);
        $this->assertSame("<redacted>", $trace["meta"]["http.request.post.pass_word"]);
        $this->assertSame("should_not_redact", $trace["meta"]["http.request.post.foo.password"]);
        $this->assertSame("value1", $trace["meta"]["http.request.post.bar.key1"]);
        $this->assertSame("value2", $trace["meta"]["http.request.post.bar.key2.baz"]);
        $this->assertSame("should_not_redact", $trace["meta"]["http.request.post.bar.key2.password"]);
        $this->assertArrayNotHasKey('http.client_ip', $trace["meta"]);
    }
}
