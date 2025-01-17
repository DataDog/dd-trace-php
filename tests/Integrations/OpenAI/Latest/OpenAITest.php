<?php

namespace DDTrace\Tests\Integrations\OpenAI;

use DDTrace\Tests\Common\IntegrationTestCase;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;

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
        // Note: Remember that DD_DOGSTATSD_URL=http://request-replayer:80 is set in the Makefile call
        ini_set("log_errors", 1);
        ini_set("error_log", __DIR__ . "/openai.log");
        self::putEnvAndReloadConfig([
            'DD_OPENAI_LOG_PROMPT_COMPLETION_SAMPLE_RATE=1.0',
            'DD_OPENAI_LOGS_ENABLED=true',
            'DD_LOGS_INJECTION=true',
            'DD_TRACE_DEBUG=true',
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
            'DD_SERVICE=openai-test',
            'DD_ENV=test',
            'DD_VERSION=1.0',
        ]);
        if (file_exists(__DIR__ . "/openai.log")) {
            $this->errorLogSize = (int)filesize(__DIR__ . "/openai.log");
        } else {
            $this->errorLogSize = 0;
        }
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_OPENAI_SERVICE',
            'DD_OPENAI_METRICS_ENABLED',
            'DD_OPENAI_LOGS_ENABLED',
            'DD_OPENAI_SPAN_CHAR_LIMIT',
            'DD_OPENAI_SPAN_PROMPT_COMPLETION_SAMPLE_RATE',
            'DD_OPENAI_LOG_PROMPT_COMPLETION_SAMPLE_RATE',
        ];
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

    private function call($resource, $openAIFn, $metaHeaders, $responseBodyArray, $openAIParameters = null, $responseCode = 200)
    {
        $this->isolateTracerSnapshot(fn: function () use ($resource, $openAIFn, $metaHeaders, $responseBodyArray, $openAIParameters, $responseCode) {
            $response = new Response($responseCode, ['Content-Type' => 'application/json; charset=utf-8', ...$metaHeaders], json_encode($responseBodyArray));
            $client = mockClient($response);
            try {
                if ($openAIParameters) {
                    $client->{$resource}()->{$openAIFn}($openAIParameters);
                } else {
                    $client->{$resource}()->{$openAIFn}();
                }
            } catch (\OpenAI\Exceptions\ErrorException $e) {
                // Ignore exceptions, they're "expected"
            }
        }, snapshotMetrics: true, logsFile: __DIR__ . "/openai.log");
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
        }, snapshotMetrics: true, logsFile: __DIR__ . "/openai.log");
    }

    public function testCreateCompletion()
    {
        $this->call('completions', 'create', metaHeaders(), completion(), [
            'model' => 'da-vince',
            'prompt' => 'hi',
            'logit_bias' => [
                '50256' => -100
            ],
            'user' => 'dd-trace'
        ]);
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

    public function testCreateEmbeddingWithMultipleInputs()
    {
        $this->call('embeddings', 'create', metaHeaders(), embeddingList(), [
            'model' => 'text-similarity-babbage-001',
            'input' => ['The food was delicious and the waiter...', 'The food was terrible and the waiter...'],
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

    public function createFineTune()
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

    public function testStreamedResponseUsability()
    {
        $this->isolateTracer(function () {
            $mockResponse = new Response(
                headers: metaHeaders(),
                body: new Stream(completionStream())
            );
            $client = mockClient($mockResponse);
            $response = $client->completions()->createStreamed([
                'model' => 'gpt-3.5-turbo-instruct',
                'prompt' => 'hi',
            ]);

            $responseIterator = $response->getIterator();
            $this->assertNotNull($responseIterator);
            $this->assertIsIterable($responseIterator);

            $expectedContent = file_get_contents(__DIR__ . '/../../OpenAI/Fixtures/Streams/CompletionCreate.txt');
            $lines = explode("\n", $expectedContent);
            for ($i = 0; $i < 10; $i++) {
                $jsonContent = substr($lines[$i], 6); // 6 is the length of 'data: '
                $encodedLine = json_decode($jsonContent, true);

                $currentItem = $responseIterator->current();
                $this->assertInstanceOf(\OpenAI\Responses\Completions\CreateStreamedResponse::class, $currentItem);
                $this->assertEqualsCanonicalizing($encodedLine, $currentItem->toArray());
                $responseIterator->next();
            }
        });
    }

    // Errors

    public function testCreateChatCompletionStreamWithError()
    {
        $this->callStreamed('chat', 'createStreamed', [], chatCompletionStreamError(), [
            'model' => 'gpt-3.5-turbo',
            'messages' => ['role' => 'user', 'content' => 'Hello!'],
        ]);
    }

    public function testListModelsWithError()
    {
        $this->call('models', 'list', [], invalidAPIKeyProvided(), null, 401);
    }

    public function testCreateCompletionsWithMultipleErrorMessages()
    {
        $this->call('completions', 'create', [], errorMessageArray(), ['model' => 'gpt-4'], 404);
    }

    public function testListModelsWithNullErrorType()
    {
        $this->call('models', 'list', [], nullErrorType(), null, 429);
    }

    // Configurations

    public function testOpenAIService()
    {
        self::putEnvAndReloadConfig([
            'DD_OPENAI_SERVICE=openai'
        ]);

        $this->call('models', 'list', metaHeaders(), modelList());
    }

    public function testMetricsDisabled()
    {
        self::putEnvAndReloadConfig([
            'DD_OPENAI_METRICS_ENABLED=false'
        ]);

        $this->call('models', 'list', metaHeaders(), modelList());
    }

    public function testLogsDisabled()
    {
        self::putEnvAndReloadConfig([
            'DD_OPENAI_LOGS_ENABLED=false'
        ]);

        $this->call('models', 'list', metaHeaders(), modelList());
    }

    public function testSpanCharLimit()
    {
        self::putEnvAndReloadConfig([
            'DD_OPENAI_SPAN_CHAR_LIMIT=3'
        ]);

        $this->call('completions', 'create', metaHeaders(), completion(), [
            'model' => 'da-vince',
            'prompt' => 'Tell me a joke',
            'logit_bias' => [
                '50256' => -100
            ],
            'user' => 'dd-trace'
        ]);
    }

    public function testSpanPromptCompletionSampleRate()
    {
        self::putEnvAndReloadConfig([
            'DD_OPENAI_SPAN_PROMPT_COMPLETION_SAMPLE_RATE=0.0'
        ]);

        $this->call('completions', 'create', metaHeaders(), completion(), [
            'model' => 'da-vince',
            'prompt' => 'Tell me a joke',
            'logit_bias' => [
                '50256' => -100
            ],
            'user' => 'dd-trace'
        ]);
    }

    public function testLogPromptCompletionSampleRate()
    {
        self::putEnvAndReloadConfig([
            'DD_OPENAI_LOG_PROMPT_COMPLETION_SAMPLE_RATE=0.0'
        ]);

        $this->call('completions', 'create', metaHeaders(), completion(), [
            'model' => 'da-vince',
            'prompt' => 'Tell me a joke',
            'logit_bias' => [
                '50256' => -100
            ],
            'user' => 'dd-trace'
        ]);
    }
}