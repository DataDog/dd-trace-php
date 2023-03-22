<?php

namespace DDTrace\Tests\Integrations\Roadrunner\V2;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;

class MultipartFormDataTest extends WebFrameworkTestCase
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
                'Content-Type: multipart/form-data; boundary=12345'
                ], <<<EOT
--12345
Content-Disposition: form-data; name="password"

should_redact
--12345
Content-Disposition: form-data; name="username"

should_not_redact
--12345
Content-Disposition: form-data; name="foo[bar]"
Content-Type: text/plain

should_not_redact
--12345--
EOT);
            $this->call($spec);
        });

        $trace = $traces[0][0];
        $this->assertSame("<redacted>", $trace["meta"]["http.request.post.password"]);
        $this->assertSame("should_not_redact", $trace["meta"]["http.request.post.username"]);
        $this->assertSame("should_not_redact", $trace["meta"]["http.request.post.foo.bar"]);
    }
}
