<?php

namespace DDTrace\Tests\Integrations\OpenAI;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\UDPServer;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
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

    private function call($resource, $openAIFn, $metaHeaders, $responseBodyArray, $openAIParameters = null)
    {
        $this->isolateTracerSnapshot(fn: function () use ($resource, $openAIFn, $metaHeaders, $responseBodyArray, $openAIParameters) {
            $response = new Response(200, ['Content-Type' => 'application/json; charset=utf-8', ...$metaHeaders], json_encode($responseBodyArray));
            $client = mockClient($response);
            if ($openAIParameters) {
                $client->{$resource}()->{$openAIFn}($openAIParameters);
            } else {
                $client->{$resource}()->{$openAIFn}();
            }
        }, snapshotMetrics: true);
    }

    private function callStreamed($resource, $openAIFn, $metaHeaders, $responseBodyArray, $openAIParameters = null)
    {
        $this->isolateTracerSnapshot(fn: function () use ($resource, $openAIFn, $metaHeaders, $responseBodyArray, $openAIParameters) {
            $response = new Response(
                headers: $metaHeaders,
                body: new Stream($responseBodyArray)
            );
            $client = mockClient($response);
            if ($openAIParameters) {
                $client->{$resource}()->{$openAIFn}($openAIParameters);
            } else {
                $client->{$resource}()->{$openAIFn}();
            }
        }, snapshotMetrics: true);
    }

    public function testCreateCompletion()
    {
        $this->call('completions', 'create', metaHeaders(), completion(), [
            'model' => 'da-vince',
            'prompt' => 'hi',
        ]);

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

    public function testCreateChatCompletion()
    {
        $this->call('chat', 'create', metaHeaders(), chatCompletion(), [
            'model' => 'gpt-3.5-turbo',
            'messages' => ['role' => 'user', 'content' => 'Hello!'],
        ]);
    }

    public function testCreateChatCompletionWithMultipleRoles()
    {
        $this->call('chat', 'create', metaHeaders(), chatCompletionDefaultExample(), [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful assistant.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Hello!',
                ]
            ]
        ]);
    }

    public function testCreateChatCompletionWithImageInput()
    {
        $this->call('chat', 'create', metaHeaders(), chatCompletionFromImageInput(), [
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "What's in this image?",
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'https://example.com/image.jpg',
                            ],
                        ]
                    ]
                ]
            ],
            'max_tokens' => 300,
        ]);
    }

    public function testCreateChatCompletionWithFunctions()
    {
        $this->call('chat', 'create', metaHeaders(), chatCompletionWithFunctions(), [
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "What's the weather like in Boston today?",
                ]
            ],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_current_weather',
                        'description' => 'Get the current weather in a given location',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'location' => [
                                    'type' => 'string',
                                    'description' => 'The city and state, e.g. San Francisco, CA'
                                ],
                                'unit' => [
                                    'type' => 'string',
                                    'enum' => ['celsius', 'fahrenheit']
                                ],
                            ],
                            'required' => ['location'],
                        ]
                    ]
                ]
            ],
            'tool_choice' => 'auto'
        ]);
    }

    public function testCreateEmbedding()
    {
        $this->call('embeddings', 'create', metaHeaders(), embeddingList(), [
            'model' => 'text-similarity-babbage-001',
            'input' => 'The food was delicious and the waiter...',
        ]);
    }

    public function testListModels()
    {
        $this->call('models', 'list', metaHeaders(), modelList());
    }

    public function testListFiles()
    {
        $this->call('files', 'list', metaHeaders(), fileListResource());
    }

    public function listFineTunes()
    {
        $this->call('fineTuning', 'listJobs', metaHeaders(), fineTuningJobListResource(), ['limit' => 3]);
    }

    public function testRetrieveModel()
    {
        $this->call('models', 'retrieve', metaHeaders(), model(), 'da-vince');
    }

    public function testRetrieveFile()
    {
        $this->call('files', 'retrieve', metaHeaders(), fileResource(), 'file-XjGxS3KTG0uNmNOK362iJua3');
    }

    public function testRetrieveFineTune()
    {
        $this->call('fineTuning', 'retrieveJob', metaHeaders(), fineTuningJobRetrieveResource(), 'ftjob-AF1WoRqd3aJAHsqc9NY7iL8F');
    }

    public function testDeleteModel()
    {
        $this->call('models', 'delete', metaHeaders(), fineTunedModelDeleteResource(), 'curie:ft-acmeco-2021-03-03-21-44-20');
    }

    public function testDeleteFile()
    {
        $this->call('files', 'delete', metaHeaders(), fileDeleteResource(), 'file-XjGxS3KTG0uNmNOK362iJua3');
    }

    public function testCreateImage()
    {
        $this->call('images', 'create', metaHeaders(), imageCreateWithUrl(), [
            'prompt' => 'A cute baby sea otter',
            'n' => 1,
            'size' => '256x256',
            'response_format' => 'url',
        ]);
    }

    public function testCreateImageEdit()
    {
        $this->call('images', 'edit', metaHeaders(), imageEditWithUrl(), [
            'image' => fileResourceResource(),
            'mask' => fileResourceResource(),
            'prompt' => 'A sunlit indoor lounge area with a pool containing a flamingo',
            'n' => 1,
            'size' => '256x256',
            'response_format' => 'url',
        ]);
    }

    public function testCreateImageVariation()
    {
        $this->call('images', 'variation', metaHeaders(), imageVariationWithUrl(), [
            'image' => fileResourceResource(),
            'n' => 1,
            'size' => '256x256',
            'response_format' => 'url',
        ]);
    }

    public function testCreateTranscriptionToText()
    {
        $this->call('audio', 'transcribe', metaHeaders(), audioTranscriptionText(), [
            'file' => audioFileResource(),
            'model' => 'whisper-1',
            'response_format' => 'text',
            'language' => 'en-US',
            'prompt' => 'Transcribe the following audio',
            'temperature' => 0.7
        ]);
    }

    public function testCreateTranscriptionToJSON()
    {
        $this->call('audio', 'transcribe', metaHeaders(), audioTranscriptionJSON(), [
            'file' => audioFileResource(),
            'model' => 'whisper-1',
            'response_format' => 'json',
        ]);
    }

    public function testCreateTranscriptionToVerboseJSON()
    {
        $this->call('audio', 'transcribe', metaHeaders(), audioTranscriptionVerboseJSON(), [
            'file' => audioFileResource(),
            'model' => 'whisper-1',
            'response_format' => 'verbose_json',
        ]);
    }

    public function testCreateTranslationToText()
    {
        $this->call('audio', 'translate', metaHeaders(), audioTranslationText(), [
            'file' => audioFileResource(),
            'model' => 'whisper-1',
            'response_format' => 'text',
            'prompt' => 'Translate the following audio',
            'temperature' => 0.7
        ]);
    }

    public function testCreateTranslationToJSON()
    {
        $this->call('audio', 'translate', metaHeaders(), audioTranslationJson(), [
            'file' => audioFileResource(),
            'model' => 'whisper-1',
            'response_format' => 'json',
        ]);
    }

    public function testCreateTranslationToVerboseJSON()
    {
        $this->call('audio', 'translate', metaHeaders(), audioTranslationVerboseJson(), [
            'file' => audioFileResource(),
            'model' => 'whisper-1',
            'response_format' => 'verbose_json',
        ]);
    }

    public function testCreateModeration()
    {
        $this->call('moderations', 'create', metaHeaders(), moderationResource(), [
            'model' => 'text-moderation-latest',
            'input' => 'I want to kill them.',
        ]);
    }

    public function testCreateFile()
    {
        $this->call('files', 'upload', metaHeaders(), fileResource(), [
            'purpose' => 'fine-tune',
            'file' => fileResourceResource(),
        ]);
    }

    public function testDownloadFile()
    {
        $this->call('files', 'download', metaHeaders(), fileContentResource(), 'file-XjGxS3KTG0uNmNOK362iJua3');
    }

    public function testCreateJob()
    {
        $this->call('fineTuning', 'createJob', metaHeaders(), fineTuningJobCreateResource(), [
            'training_file' => 'file-abc123',
            'validation_file' => null,
            'model' => 'gpt-3.5-turbo-0613',
            'hyperparameters' => [
                'n_epochs' => 4,
            ],
            'suffix' => null,
        ]);
    }

    public function testCancelFineTune()
    {
        $this->call('fineTunes', 'cancel', metaHeaders(), [...fineTuneResource(), 'status' => 'cancelled'], 'ftjob-AF1WoRqd3aJAHsqc9NY7iL8F');
    }

    public function testListFineTuneEvents()
    {
        $this->call('fineTunes', 'listEvents', metaHeaders(), fineTuneListEventsResource(), 'ftjob-AF1WoRqd3aJAHsqc9NY7iL8F');
    }

    // Streamed Responses

    public function testCreateCompletionStream()
    {
        $this->callStreamed('completions', 'createStreamed', metaHeaders(), completionStream(), [
            'model' => 'gpt-3.5-turbo-instruct',
            'prompt' => 'hi',
        ]);
    }

    public function testCreateChatCompletionStream()
    {
        $this->callStreamed('chat', 'createStreamed', metaHeaders(), chatCompletionStream(), [
            'model' => 'gpt-3.5-turbo',
            'messages' => ['role' => 'user', 'content' => 'Hello!'],
        ]);
    }

    public function testListFineTuneEventsStream()
    {
        $this->callStreamed('fineTunes', 'listEventsStreamed', metaHeaders(), fineTuneListEventsStream(), 'ft-MaoEAULREoazpupm8uB7qoIl');
    }
}
