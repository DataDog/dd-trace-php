<?php

namespace DDTrace\Tests\Integrations\OpenAI;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\UDPServer;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\Psr18ClientDiscovery;
use Mockery;
use OpenAI\Client;
use OpenAI\Enums\Transporter\ContentType;
use OpenAI\ValueObjects\ApiKey;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;
use OpenAI\ValueObjects\Transporter\QueryParams;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class OpenAITest extends IntegrationTestCase
{
    private $errorLogSize = 0;

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
    }

    private function checkErrors()
    {
        $diff = file_get_contents(__DIR__ . "/openai.log", false, null, $this->errorLogSize);
        $out = "";
        foreach (explode("\n", $diff) as $line) {
            if (preg_match("(\[ddtrace] \[(error|warn|deprecated|warning)])", $line)) {
                $out .= $line;
            }
        }
        return $out;
    }

    protected function ddSetUp()
    {
        // Note: Remember that DD_DOGSTATSD_URL=http://127.0.0.1:9876 is set in the Makefile call
        ini_set("log_errors", 1);
        ini_set("error_log", __DIR__ . "/openai.log");
        self::putEnvAndReloadConfig([
            'DD_OPENAI_LOG_PROMPT_COMPLETION_SAMPLE_RATE=1.0',
            'DD_OPENAI_LOGS_ENABLED=true',
            'DD_LOGS_INJECTION=true',
            'DD_TRACE_DEBUG=true',
            'DD_TRACE_GENERATE_ROOT_SPAN=0'
        ]);
        $this->errorLogSize = (int)filesize(__DIR__ . "/openai.log");
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
        //shell_exec("rm -f " . __DIR__ . "/openai.log");
        $error = $this->checkErrors();
        if ($error) {
            $this->fail("Got error:\n$error");
        }
    }

    public function testChatCompletionCreate()
    {
        $server = new UDPServer('127.0.0.1', 9876);

        $this->isolateTracerSnapshot(function () {
            $response = new Response(200, ['Content-Type' => 'application/json; charset=utf-8', ...metaHeaders()], json_encode(completion()));
            $client = mockClient($response);


            $client->completions()->create([
                'model' => 'da-vince',
                'prompt' => 'hi',
            ]);

        });

        $actualMetrics = $server->dump();
        $server->close();

        // Check Metrics
        $expectedMetrics = <<<EOF
openai.request.duration:\d\d+|d|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
openai.tokens.prompt:1|d|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
openai.tokens.completion:16|d|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
openai.tokens.total:17|d|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
openai.ratelimit.requests:3000|g|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
openai.ratelimit.tokens:250000|g|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
openai.ratelimit.remaining.requests:2999|g|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
openai.ratelimit.remaining.tokens:249989|g|#openai.request.model:da-vince,model:da-vince,openai.organization.name:org-1234,openai.user.api_key:sk-...9d5d,openai.request.endpoint:\/v1\/completions
EOF;
        $this->assertMatchesRegularExpression("/$expectedMetrics/", $actualMetrics);


        // Check Logs
        $diff = file_get_contents(__DIR__ . "/openai.log", false, null, $this->errorLogSize);
        $lines = array_values(array_filter(explode("\n", $diff), function ($line) {
            return str_starts_with($line, '{');
        }));
        if (count($lines) === 0) {
            $this->fail("No log record found");
        } elseif (count($lines) > 1) {
            $this->fail("More than one log record found. Received:\n$diff");
        }
        $line = $lines[0];
        $logRecord = json_decode($line, true);

        $this->assertSame('sampled createCompletion', $logRecord['message']);
        $this->assertSame([
            'openai.request.method' => 'POST',
            'openai.request.endpoint' => '/v1/completions',
            'openai.request.model' => 'da-vince',
            'openai.organization.name' => 'org-1234',
            'openai.user.api_key' => 'sk-...9d5d',
            'prompt' => 'hi',
            'choices.0.finish_reason' => 'length',
            'choices.0.text' => 'el, she elaborates more on the Corruptor\'s role, suggesting K',
        ], $logRecord['context']);
        $this->assertSame('info', $logRecord['status']);

        $this->assertArrayHasKey('timestamp', $logRecord);
        $this->assertArrayHasKey('dd.trace_id', $logRecord);
        $this->assertArrayHasKey('dd.span_id', $logRecord);
    }
}
