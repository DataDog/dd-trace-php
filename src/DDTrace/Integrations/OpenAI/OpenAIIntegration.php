<?php

namespace DDTrace\Integrations\OpenAI;

use DDTrace\Integrations\Integration;
use DDTrace\Log\DatadogLogger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use function DDTrace\dogstatsd_count;
use function DDTrace\dogstatsd_distribution;
use function DDTrace\dogstatsd_gauge;

class OpenAIIntegration extends Integration
{
    const NAME = 'openai';

    const COMMON_PARAMETERS = [
        'best_of' => true,
        'echo' => true,
        'logprobs' => true,
        'max_tokens' => true,
        'model' => true,
        'n' => true,
        'presence_penalty' => true,
        'frequency_penalty' => true,
        'stop' => true,
        'suffix' => true,
        'temperature' => true,
        'top_p' => true,
        'user' => true,
        'file_id' => true
    ];

    /**
     * @param string $apiKey The OpenAI API key to format
     * @return string The formatted API key 'XXX...YYYY', where XXX and YYYY are respectively the first three and last
     * four characters of the provided OpenAI API Key
     */
    public static function formatAPIKey(string $apiKey): string
    {
        return \substr($apiKey, 0, 3) . '...' . \substr($apiKey, -4);
    }

    public static function shouldSample(float $rate): bool
    {
        $random = \mt_rand() / \mt_getrandmax();
        return $random <= $rate;
    }

    /**
     * Add instrumentation to OpenAI API Requests
     */
    public function init(): int
    {
        $logger = \dd_trace_env_config('DD_OPENAI_LOGS_ENABLED') ? new DatadogLogger() : null;

        $targets = [
            ['OpenAI\Resources\Completions', 'create', 'createCompletion', 'POST', '/v1/completions'],
            ['OpenAI\Resources\Chat', 'create', 'createChatCompletion', 'POST', '/v1/chat/completions'],
            ['OpenAI\Resources\Embeddings', 'create', 'createEmbedding', 'POST', '/v1/embeddings'],
            ['OpenAI\Resources\Models', 'list', 'listModels', 'GET', '/v1/models'],
            ['OpenAI\Resources\Files', 'list', 'listFiles', 'GET', '/v1/files'],
            ['OpenAI\Resources\FineTuning', 'listJobs', 'listFineTunes', 'GET', '/v1/fine-tunes'],
            ['OpenAI\Resources\Models', 'retrieve', 'retrieveModel', 'GET', '/v1/models/*'],
            ['OpenAI\Resources\Files', 'retrieve', 'retrieveFile', 'GET', '/v1/files/*'],
            ['OpenAI\Resources\FineTuning', 'retrieveJob', 'retrieveFineTune', 'GET', '/v1/fine-tunes/*'],
            ['OpenAI\Resources\Models', 'delete', 'deleteModel', 'DELETE', '/v1/models/*'],
            ['OpenAI\Resources\Files', 'delete', 'deleteFile', 'DELETE', '/v1/files/*'],
            ['OpenAI\Resources\Images', 'create', 'createImage', 'POST', '/v1/images/generations'],
            ['OpenAI\Resources\Images', 'edit', 'createImageEdit', 'POST', '/v1/images/edits'],
            ['OpenAI\Resources\Images', 'variation', 'createImageVariation', 'POST', '/v1/images/variations'],
            ['OpenAI\Resources\Audio', 'transcribe', 'createTranscription', 'POST', '/v1/audio/transcriptions'],
            ['OpenAI\Resources\Audio', 'translate', 'createTranslation', 'POST', '/v1/audio/translations'],
            ['OpenAI\Resources\Moderations', 'create', 'createModeration', 'POST', '/v1/moderations'],
            ['OpenAI\Resources\Files', 'upload', 'createFile', 'POST', '/v1/files'],
            ['OpenAI\Resources\Files', 'download', 'downloadFile', 'GET', '/v1/files/*/content'],
            ['OpenAI\Resources\FineTuning', 'createJob', 'createFineTune', 'POST', '/v1/fine-tunes'],
            ['OpenAI\Resources\FineTunes', 'cancel', 'cancelFineTune', 'POST', '/v1/fine-tunes/*/cancel'],
            ['OpenAI\Resources\FineTunes', 'listEvents', 'listFineTuneEvents', 'GET', '/v1/fine-tunes/*/events'],
        ];

        $streamedTargets = [
            ['OpenAI\Resources\Completions', 'createStreamed', 'createCompletion', 'POST', '/v1/completions'],
            ['OpenAI\Resources\Chat', 'createStreamed', 'createChatCompletion', 'POST', '/v1/chat/completions'],
            ['OpenAI\Resources\FineTunes', 'listEventsStreamed', 'listFineTuneEvents', 'GET', '/v1/fine-tunes/*/events'],
        ];

        \DDTrace\hook_method(
            'OpenAI\Transporters\HttpTransporter',
            '__construct',
            function ($This, $scope, $args) {
                /** @var \OpenAI\ValueObjects\Transporter\BaseUri $baseUri */
                $baseUri = $args[1];
                /** @var \OpenAI\ValueObjects\Transporter\Headers $headers */
                $headers = $args[2];
                /** @var array<string, string> $data */
                $headers = $headers->toArray();

                $clientData = [];
                $clientData['baseUri'] = $baseUri->toString();
                $clientData['headers'] = $headers;

                if (isset($headers['Authorization'])) {
                    $authorizationHeader = $headers['Authorization'];
                    $apiKey = \substr($authorizationHeader, 7); // Format: "Bearer <api_key>
                    $clientData['apiKey'] = OpenAIIntegration::formatAPIKey($apiKey);
                } else {
                    $clientData['apiKey'] = "";
                }

                ObjectKVStore::put($This, 'client_data', $clientData);
            }
        );

        \DDTrace\hook_method(
            "OpenAI\Resources\Concerns\Transportable",
            '__construct',
            function ($This, $scope, $args) {
                $transporter = $args[0];
                ObjectKVStore::put($This, 'transporter', $transporter);
            }
        );

        foreach ($targets as [$class, $method, $methodName, $httpMethod, $endpoint]) {
            \DDTrace\trace_method(
                $class,
                $method,
                [
                    'prehook' => function (\DDTrace\SpanData $span, $args) use ($methodName) {
                        $clientData = ObjectKVStore::get($this, 'client_data');
                        if (\is_null($clientData)) {
                            $transporter = ObjectKVStore::get($this, 'transporter');
                            $clientData = ObjectKVStore::get($transporter, 'client_data');
                            ObjectKVStore::put($this, 'client_data', $clientData);
                        }
                        /** @var array{baseUri: string, headers: string, apiKey: ?string} $clientData */
                        OpenAIIntegration::handleRequest(
                            span: $span,
                            methodName: $methodName,
                            args: $args,
                            basePath: $clientData['baseUri'],
                            apiKey: $clientData['apiKey'],
                        );
                    },
                    'posthook' => function (\DDTrace\SpanData $span, $args, $response) use ($logger, $httpMethod, $endpoint) {
                        /** @var (\OpenAI\Contracts\ResponseContract&\OpenAI\Contracts\ResponseHasMetaInformationContract)|string $response */
                        // Files::download - i.e., downloadFile - returns a string instead of a Response instance
                        OpenAIIntegration::handleResponse(
                            span: $span,
                            logger: $logger,
                            headers: $response ? (method_exists($response, 'meta') ? $response->meta()->toArray() : []) : [],
                            response: \is_string($response) ? $response : ($response ? $response->toArray() : []),
                            httpMethod: $httpMethod,
                            endpoint: $endpoint,
                        );
                    }
                ]
            );
        }

        foreach ($streamedTargets as [$class, $method, $methodName, $httpMethod, $endpoint]) {
            \DDTrace\trace_method(
                $class,
                $method,
                [
                    'prehook' => function (\DDTrace\SpanData $span, $args) use ($methodName) {
                        $clientData = ObjectKVStore::get($this, 'client_data');
                        if (\is_null($clientData)) {
                            $transporter = ObjectKVStore::get($this, 'transporter');
                            $clientData = ObjectKVStore::get($transporter, 'client_data');
                            ObjectKVStore::put($this, 'client_data', $clientData);
                        }
                        /** @var array{baseUri: string, headers: string, apiKey: ?string} $clientData */
                        OpenAIIntegration::handleRequest(
                            span: $span,
                            methodName: $methodName,
                            args: $args,
                            basePath: $clientData['baseUri'],
                            apiKey: $clientData['apiKey'],
                            streamed: true
                        );
                    },
                    'posthook' => function (\DDTrace\SpanData $span, $args, $response) use ($logger, $httpMethod, $endpoint) {
                        /** @var \OpenAI\Responses\StreamResponse $response */
                        OpenAIIntegration::handleStreamedResponse(
                            span: $span,
                            logger: $logger,
                            headers: method_exists($response, 'meta') ? $response->meta()->toArray() : [],
                            response: \is_string($response) ? $response : ($response ? $response->getIterator() : []),
                            httpMethod: $httpMethod,
                            endpoint: $endpoint,
                        );
                    }
                ]
            );
        }

        return Integration::LOADED;
    }

    public static function normalizeRequestPayload(
        string $methodName,
        array  $args
    ): array
    {
        switch ($methodName) {
            case 'listModels':
            case 'listFiles':
            case 'listFineTunes':
                // No Argument
                return [];

            case 'retrieveModel': // public function retrieve(string $model): RetrieveResponse
                return [
                    'id' => $args[0],
                ];

            case 'createFile': // public function upload(array $parameters): CreateResponse
                /** @var array $parameters */
                $parameters = $args[0];
                return [
                    'file' => $parameters['file'] ?? null,
                    'purpose' => $parameters['purpose'] ?? null,
                ];

            case 'deleteFile': // public function delete(string $file): DeleteResponse
            case 'retrieveFile': // public function retrieve(string $file): RetrieveResponse
            case 'downloadFile': // public function download(string $file): string
                return [
                    'file_id' => $args[0],
                ];

            case 'listFineTuneEvents': // public function listEvents(string $fineTuneId): ListEventsResponse
                return [
                    'fine_tune_id' => $args[0],
                ];

            case 'retrieveFineTune': // public function retrieve(string $fineTuneId): RetrieveResponse
            case 'deleteModel': // public function delete(string $model): DeleteResponse
            case 'cancelFineTune': // public function cancel(string $fineTuneId): RetrieveResponse
                return [
                    'fine_tune_id' => $args[0],
                ];

            case 'createImageEdit': // public function edit(array $parameters): EditResponse
                /** @var array $parameters */
                $parameters = $args[0];
                return [
                    'file' => $parameters['image'] ?? null,
                    'prompt' => $parameters['prompt'] ?? null,
                    'mask' => $parameters['mask'] ?? null,
                    'n' => $parameters['n'] ?? null,
                    'size' => $parameters['size'] ?? null,
                    'response_format' => $parameters['response_format'] ?? null,
                    'user' => $parameters['user'] ?? null,
                ];

            case 'createImageVariation': // public function variation(array $parameters): VariationResponse
                /** @var array $parameters */
                $parameters = $args[0];
                return [
                    'file' => $parameters['image'] ?? null,
                    'n' => $parameters['n'] ?? null,
                    'size' => $parameters['size'] ?? null,
                    'response_format' => $parameters['response_format'] ?? null,
                    'user' => $parameters['user'] ?? null,
                ];

            case 'createTranscription': // public function transcribe(array $parameters): TranscriptionResponse
            case 'createTranslation': // public function translate(array $parameters): TranslationResponse
                /** @var array $parameters */
                $parameters = $args[0];
                return [
                    'file' => $parameters['file'] ?? null,
                    'model' => $parameters['model'] ?? null,
                    'prompt' => $parameters['prompt'] ?? null,
                    'response_format' => $parameters['response_format'] ?? null,
                    'temperature' => $parameters['temperature'] ?? null,
                    'language' => $parameters['user'] ?? null,
                ];
        }

        // Remaining OpenAI methods take a single array argument $parameters
        return $args[0];
    }


    public static function handleRequest(
        SpanData $span,
        string   $methodName,
        array    $args,
        string   $basePath,
        string   $apiKey,
        bool     $streamed = false
    )
    {
        $payload = OpenAIIntegration::normalizeRequestPayload($methodName, $args);

        $span->name = 'openai.request';
        $span->resource = isset($payload['model']) ? $methodName . '/' . $payload['model'] : $methodName;
        $span->type = Type::OPENAI;
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
        $span->meta['openai.user.api_key'] = $apiKey;
        $span->meta['openai.api_base'] = $basePath;
        $span->metrics['_dd.measured'] = 1;

        foreach ($payload as $key => $value) {
            if (isset(OpenAIIntegration::COMMON_PARAMETERS[$key]) && !\is_null($value)) {
                $span->meta["openai.request.$key"] = $value;
            }
        }

        $tags = [];

        // createCompletion, createImage, createImageEdit, createTranscription, createTranslation
        if (array_key_exists('prompt', $payload)) {
            $prompt = $payload['prompt'];
            if (OpenAIIntegration::shouldSample(\dd_trace_env_config('DD_OPENAI_SPAN_PROMPT_COMPLETION_SAMPLE_RATE'))) {
                if (\is_string($prompt)) {
                    $span->meta["openai.request.prompt"] = OpenAIIntegration::normalizeStringOrTokenArray($prompt);
                } elseif (\is_array($prompt)) {
                    foreach ($prompt as $idx => $value) {
                        $span->meta["openai.request.prompt.$idx"] = OpenAIIntegration::normalizeStringOrTokenArray($value);
                    }
                }
            }

            if ($streamed) {
                $numPromptTokens = 0;

                if (\is_string($prompt)) {
                    $numPromptTokens += self::estimateTokens($prompt);
                } elseif (\is_array($prompt)) {
                    foreach ($prompt as $value) {
                        $numPromptTokens += self::estimateTokens($value);
                    }
                }

                $tags['openai.request.prompt_tokens_estimated'] = 1;
                $tags['openai.response.usage.prompt_tokens'] = $numPromptTokens;
            }
        }

        // createEdit, createEmbedding, createModeration
        if (array_key_exists('input', $payload)) {
            $input = $payload['input'];
            if (\is_string($input)) {
                $span->meta["openai.request.input"] = OpenAIIntegration::normalizeStringOrTokenArray($input);
            } else {
                foreach ($input as $idx => $value) {
                    $span->meta["openai.request.input.$idx"] = OpenAIIntegration::normalizeStringOrTokenArray($value);
                }
            }
        }

        // createChatCompletion, createCompletion
        if (array_key_exists('logit_bias', $payload) && \is_array($payload['logit_bias'])) {
            foreach ($payload['logit_bias'] as $tokenID => $bias) {
                $span->meta["openai.request.logit_bias.$tokenID"] = $bias;
            }
        }

        if ($streamed) {
            $tags['openai.request.streamed'] = 1;
        }

        switch ($methodName) {
            case 'createFineTune':
                $tags = OpenAIIntegration::createFineTuneRequestExtraction($payload);
                break;

            case 'createImage':
            case 'createImageEdit':
            case 'createImageVariation':
                $tags = OpenAIIntegration::commonCreateImageRequestExtraction($payload);
                break;

            case 'createChatCompletion':
                $tags = OpenAIIntegration::createChatCompletionRequestExtraction($payload, $streamed);
                break;

            case 'createFile':
            case 'retrieveFile':
                $tags = OpenAIIntegration::commonFileRequestExtraction($payload);
                break;

            case 'createTranscription':
            case 'createTranslation':
                $tags = OpenAIIntegration::commonCreateAudioRequestExtraction($payload);
                break;

            case 'retrieveModel':
                $tags = OpenAIIntegration::retrieveModelRequestExtraction($payload);
                break;

            case 'listFineTuneEvents':
            case 'retrieveFineTune':
            case 'deleteModel':
            case 'cancelFineTune':
                $tags = OpenAIIntegration::commonLookupFineTuneRequestExtraction($payload);
                break;

            case 'createEdit':
                $tags = OpenAIIntegration::createEditRequestExtraction($payload);
                break;
        }

        foreach ($tags as $key => $value) {
            if (\is_null($value)) {
                continue;
            } elseif (\is_numeric($value)) {
                $span->metrics[$key] = $value;
            } else {
                $span->meta[$key] = $value;
            }
        }
    }

    public static function handleResponse(
        SpanData        $span,
        ?DatadogLogger  $logger,
        array           $headers,
        array|string    $response,
        string          $httpMethod,
        string          $endpoint,
    )
    {
        $methodName = \explode('/', $span->resource)[0];

        if ($methodName === 'downloadFile') {
            $response = ['bytes' => \strlen($response)];
        }

        $tags = [
            'openai.request.endpoint' => $endpoint,
            'openai.request.method' => $httpMethod,

            'openai.organization.id' => $response['organization_id'] ?? null, // Only available in fine-tunes endpoint
            'openai.organization.name' => $headers['openai-organization'] ?? null,

            'openai.response.model' => $headers['openai-model'] ?? $response['model'] ?? null, // Specific model, often undefined
            'openai.response.id' => $headers['x-request-id'] ?? $response['id'] ?? null, // Common creation value, numeric epoch
            'openai.response.deleted' => $response['deleted'] ?? null, // Common boolean field in delete responses

            // The OpenAI API appears to use both created and created_at in different responses
            // Here we're consciously choosing to surface this inconsistency instead of normalizing
            'openai.response.created' => $response['created'] ?? null,
            'openai.response.created_at' => $response['created_at'] ?? null,

            'openai.response.object' => $response['object'] ?? null,
        ];

        switch ($methodName) {
            case 'createModeration':
                $tags += OpenAIIntegration::createModerationResponseExtraction($response);
                break;

            case 'createCompletion':
            case 'createChatCompletion':
            case 'createEdit':
                $tags += OpenAIIntegration::commonCreateResponseExtraction($response);
                break;

            case 'listFiles':
            case 'listFineTunes':
            case 'listFineTuneEvents':
                $tags += OpenAIIntegration::commonListCountResponseExtraction($response);
                break;

            case 'createEmbedding':
                $tags += OpenAIIntegration::createEmbeddingResponseExtraction($response);
                break;

            case 'createFile':
            case 'retrieveFile':
                $tags += OpenAIIntegration::createRetrieveFileResponseExtraction($response);
                break;

            case 'deleteFile':
                $tags += OpenAIIntegration::deleteFileResponseExtraction($response);
                break;

            case 'downloadFile':
                $tags += OpenAIIntegration::downloadFileResponseExtraction($response);
                break;

            case 'createFineTune':
            case 'retrieveFineTune':
            case 'cancelFineTune':
                $tags += OpenAIIntegration::commonFineTuneResponseExtraction($response);
                break;

            case 'createTranscription':
            case 'createTranslation':
                $tags += OpenAIIntegration::createAudioResponseExtraction($response);
                break;

            case 'createImage':
            case 'createImageEdit':
            case 'createImageVariation':
                $tags += OpenAIIntegration::commonImageResponseExtraction($response);
                break;

            case 'listModels':
                $tags += OpenAIIntegration::listModelsResponseExtraction($response);
                break;

            case 'retrieveModel':
                $tags += OpenAIIntegration::retrieveModelResponseExtraction($response);
                break;
        }

        foreach ($tags as $key => $value) {
            if (\is_null($value)) {
                continue;
            } elseif (\is_numeric($value)) {
                $span->metrics[$key] = $value;
            } else {
                $span->meta[$key] = $value;
            }
        }

        OpenAIIntegration::sendMetrics(
            span: $span,
            headers: $headers,
            duration: $span->getDuration(),
            promptTokens: (int)($response['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int)($response['usage']['completion_tokens'] ?? 0)
        );

        OpenAIIntegration::sendLog(
            logger: $logger,
            span: $span,
            methodName: $methodName,
            error: $span->exception ? true : false
        );
    }

    public static function getLogTags(
        SpanData $span,
        string   $methodName
    ): array
    {
        $tags = [
            'env' => \dd_trace_env_config('DD_ENV'),
            'version' => \dd_trace_env_config('DD_VERSION'),
            'service' => \dd_trace_env_config('DD_OPENAI_SERVICE') ??  \dd_trace_env_config('DD_SERVICE') ?? $span->service,
            'openai.request.method' => $span->meta['openai.request.method'] ?? null,
            'openai.request.endpoint' => $span->meta['openai.request.endpoint'] ?? null,
            'openai.request.model' => $span->meta['openai.request.model'] ?? null,
            'openai.organization.name' => $span->meta['openai.organization.name'] ?? null,
            'openai.user.api_key' => $span->meta['openai.user.api_key'] ?? null,
        ];

        switch ($methodName) {
            case 'createCompletion':
                $tags += [
                    'prompt' => $span->meta['openai.request.prompt'] ?? null,
                    'choices.0.finish_reason' => $span->meta['openai.response.choices.0.finish_reason'] ?? null,
                    'choices.0.text' => $span->meta['openai.response.choices.0.text'] ?? null,
                ];
                break;
            case 'createChatCompletion':
                $tags += [
                    'messages.0.content' => $span->meta['openai.request.message.0.content'] ?? null,
                    'completion.0.finish_reason' => $span->meta['openai.response.choices.0.finish_reason'] ?? null,
                    'completion.0.message.content' => $span->meta['openai.response.choices.0.message.content'] ?? null,
                ];
                break;
            case 'createEdit':
                $tags += [
                    'input' => $span->meta['openai.request.input'] ?? null,
                    'instruction' => $span->meta['openai.request.instruction'] ?? null,
                    'choices.0.text' => $span->meta['openai.response.choices.0.text'] ?? null,
                ];
                break;
            case 'createImage':
            case 'createImageEdit':
            case 'createImageVariation':
                $tags += [
                    'prompt' => $span->meta['openai.request.prompt'] ?? null,
                    'image' => $span->meta['openai.request.image'] ?? null,
                    'mask' => $span->meta['openai.request.mask'] ?? null,
                    'choices.0.b64_json' => $span->meta['openai.response.images.0.b64_json'] ?? null,
                    'choices.0.url' => $span->meta['openai.response.images.0.url'] ?? null,
                ];
                break;
            case 'createTranscription':
            case 'createTranslation':
                $tags += [
                    'file' => $span->meta['openai.request.file'] ?? null,
                    'prompt' => $span->meta['openai.request.prompt'] ?? null,
                    'choices.0.text' => $span->meta['openai.response.choices.0.text'] ?? null,
                ];
                break;

        }

        return \array_filter($tags, fn($v) => !empty($v));
    }

    public static function sendLog(
        ?DatadogLogger $logger,
        SpanData       $span,
        string         $methodName,
        bool           $error = false
    )
    {
        if (!(dd_trace_env_config('DD_OPENAI_LOGS_ENABLED')
            && OpenAIIntegration::shouldSample(\dd_trace_env_config('DD_OPENAI_LOG_PROMPT_COMPLETION_SAMPLE_RATE')))
        ) {
            return;
        }

        $sampling = \DDTrace\get_priority_sampling();
        if ($sampling === DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT
            || $sampling === DD_TRACE_PRIORITY_SAMPLING_USER_REJECT
        ) {
            return;
        }

        $tags = OpenAIIntegration::getLogTags(
            span: $span,
            methodName: $methodName
        );


        $logMethod = $error ? 'error' : 'info';
        $logMessage = "sampled $methodName";
        $logger->$logMethod($logMessage, $tags);
    }

    public static function sendMetrics(
        SpanData  $span,
        array     $headers,
        int       $duration,
        int       $promptTokens = 0,
        int       $completionTokens = 0,
        bool      $estimated = false
    )
    {
        if (!dd_trace_env_config('DD_OPENAI_METRICS_ENABLED')) {
            return;
        }

        $errorType = null;
        if ($span->exception instanceof \OpenAI\Exceptions\ErrorException) {
            $errorType = $span->exception->getErrorType() ?? $span->exception->getErrorCode() ?? null;
        } elseif ($span->exception) {
            $errorType = \get_class($span->exception);
        }


        $tags = [
            'env' => \dd_trace_env_config('DD_ENV'),
            'service' => \dd_trace_env_config('DD_OPENAI_SERVICE') ?? \dd_trace_env_config('DD_SERVICE') ?? $span->service,
            'version' => \dd_trace_env_config('DD_VERSION'),
            'openai.request.model' => $span->meta['openai.request.model'] ?? null,
            'model' => $span->meta['openai.request.model'] ?? null,
            'openai.organization.id' => $span->meta['openai.organization.id'] ?? null,
            'openai.organization.name' => $span->meta['openai.organization.name'] ?? null,
            'openai.user.api_key' => $span->meta['openai.user.api_key'] ?? null,
            'openai.request.endpoint' => $span->meta['openai.request.endpoint'] ?? null,
            'openai.estimated' => $estimated,
            'openai.request.error' => $span->exception ? 1 : 0,
            'error_type' => $errorType,
        ];

        $tags = array_filter($tags, fn($v) => !empty($v));

        dogstatsd_distribution(
            'openai.request.duration',
            $duration, // Duration is in ns
            $tags
        );
        if ($span->exception) {
            dogstatsd_count('openai.request.error', 1, $tags);
        }

        if ($promptTokens) {
            dogstatsd_distribution(
                'openai.tokens.prompt',
                $promptTokens,
                $tags
            );
        }
        if ($completionTokens) {
            dogstatsd_distribution(
                'openai.tokens.completion',
                $completionTokens,
                $tags
            );
        }
        if ($promptTokens || $completionTokens) {
            dogstatsd_distribution(
                'openai.tokens.total',
                $promptTokens + $completionTokens,
                $tags
            );
        }

        if (isset($headers['x-ratelimit-limit-requests'])) {
            dogstatsd_gauge(
                'openai.ratelimit.requests',
                (int)$headers['x-ratelimit-limit-requests'],
                $tags
            );
        }

        if (isset($headers['x-ratelimit-limit-tokens'])) {
            dogstatsd_gauge(
                'openai.ratelimit.tokens',
                (int)$headers['x-ratelimit-limit-tokens'],
                $tags
            );
        }

        if (isset($headers['x-ratelimit-remaining-requests'])) {
            dogstatsd_gauge(
                'openai.ratelimit.remaining.requests',
                (int)$headers['x-ratelimit-remaining-requests'],
                $tags
            );
        }

        if (isset($headers['x-ratelimit-remaining-tokens'])) {
            dogstatsd_gauge(
                'openai.ratelimit.remaining.tokens',
                (int)$headers['x-ratelimit-remaining-tokens'],
                $tags
            );
        }
    }

    public static function createFineTuneRequestExtraction(array $payload): array
    {
        return [
            'openai.request.training_file' => $payload['training_file'] ?? null,
            'openai.request.validation_file' => $payload['validation_file'] ?? null,
            'openai.request.n_epochs' => $payload['hyperparams']['n_epochs'] ?? null,
            'openai.request.batch_size' => $payload['hyperparams']['batch_size'] ?? null,
            'openai.request.learning_rate_multiplier' => $payload['hyperparams']['learning_rate_multiplier'] ?? null,
        ];
    }

    public static function commonCreateImageRequestExtraction(array $payload): array
    {
        $image = null;
        if (isset($payload['image']) && is_resource($payload['image'])) {
            $metadata = stream_get_meta_data($payload['image']);
            $uri = $metadata['uri'];
            $image = basename($uri);
        } elseif (isset($payload['image']) && is_string($payload['image'])) {
            $image = basename($payload['image']);
        }

        $mask = null;
        if (isset($payload['mask']) && is_resource($payload['mask'])) {
            $metadata = stream_get_meta_data($payload['mask']);
            $uri = $metadata['uri'];
            $mask = basename($uri);
        } elseif (isset($payload['mask']) && is_string($payload['mask'])) {
            $mask = basename($payload['mask']);
        }

        return [
            'openai.request.image' => $image,
            'openai.request.mask' => $mask,
            'openai.request.size' => $payload['size'] ?? null,
            'openai.request.response_format' => $payload['response_format'] ?? null,
            'openai.request.language' => $payload['language'] ?? null,
        ];
    }

    public static function createChatCompletionRequestExtraction(array $payload, bool $streamed = false): array
    {
        $messages = $payload['messages'] ?? [];

        $tags = [];
        if (isset($messages[0]) && is_array($messages[0])) {
            foreach ($messages as $idx => $message) {
                $tags["openai.request.message.$idx.content"] = $message['content'] ?? null;
                $tags["openai.request.message.$idx.role"] = $message['role'] ?? null;
            }
        } else {
            $tags['openai.request.message.0.content'] = $messages['content'] ?? null;
            $tags['openai.request.message.0.role'] = $messages['role'] ?? null;
        }

        if ($streamed) {
            // Iterate over the $payload['messages'] array and estimate the number of tokens
            // Payload can be either an array ['role' => XX, 'content' => XX], or an array of these
            $numPromptTokens = 0;
            foreach ($messages as $key => $value) {
                if ($key === 'content') {
                    $numPromptTokens += self::estimateTokens($value);
                } elseif (is_array($value) && isset($value['content'])) {
                    $numPromptTokens += self::estimateTokens($value['content']);
                }
            }
            $tags['openai.request.prompt_tokens_estimated'] = 1;
            $tags['openai.response.usage.prompt_tokens'] = $numPromptTokens;
        }

        return $tags;
    }

    public static function commonFileRequestExtraction(array $payload): array
    {
        $file = null;
        if (isset($payload['file']) && is_resource($payload['file'])) {
            $metadata = stream_get_meta_data($payload['file']);
            $uri = $metadata['uri'];
            $file = basename($uri);
        } elseif (isset($payload['file']) && is_string($payload['file'])) {
            $file = basename($payload['file']);
        }

        return [
            'openai.request.purpose' => $payload['purpose'] ?? null,
            'openai.request.filename' => $file,
        ];
    }

    public static function commonCreateAudioRequestExtraction(array $payload): array
    {
        $filename = null;
        if (isset($payload['file']) && is_resource($payload['file'])) {
            $metadata = stream_get_meta_data($payload['file']);
            $uri = $metadata['uri'];
            $filename = basename($uri);
        } elseif (isset($payload['file']) && is_string($payload['file'])) {
            $filename = basename($payload['file']);
        }


        return [
            'openai.request.response_format' => $payload['response_format'] ?? null,
            'openai.request.language' => $payload['language'] ?? null,
            'openai.request.filename' => $filename,
        ];
    }

    public static function retrieveModelRequestExtraction(array $payload): array
    {
        return [
            'openai.request.id' => $payload['id'] ?? null,
        ];
    }

    public static function commonLookupFineTuneRequestExtraction(array $payload): array
    {
        return [
            'openai.request.fine_tune_id' => $payload['fine_tune_id'] ?? null,
            'openai.request.stream' => $payload['stream'] ?? null,
        ];
    }

    public static function createEditRequestExtraction(array $payload): array
    {
        return [
            'openai.request.instruction' => $payload['instruction'] ?? null,
        ];
    }

    public static function createModerationResponseExtraction(array $payload): array
    {
        $tags = [
            'openai.response.id' => $payload['id'] ?? null,
        ];

        if (empty($payload['results'])) {
            return $tags;
        }

        if (isset($payload['results'][0]) && is_array($payload['results'][0])) {
            $payload['results'] = $payload['results'][0];
        }

        $tags['openai.response.flagged'] = filter_var($payload['results']['flagged'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        $categories = $payload['results']['categories'] ?? [];
        foreach ($categories as $category => $flag) {
            $tags["openai.response.categories.$category"] = $flag;
        }

        $category_scores = $payload['results']['category_scores'] ?? [];
        foreach ($category_scores as $category_score => $score) {
            $tags["openai.response.category_scores.$category_score"] = $score;
        }

        return $tags;
    }

    public static function commonCreateResponseExtraction(array $payload): array
    {
        $tags = OpenAIIntegration::usageExtraction($payload);

        $choices = $payload['choices'] ?? [];
        if (empty($choices)) {
            return $tags;
        }

        $tags['openai.response.choices_count'] = \count($choices);

        foreach ($choices as $idx => $choice) {
            $tags["openai.response.choices.$idx.finish_reason"] = $choice["finish_reason"];
            $tags["openai.response.choices.$idx.logprobs"] = ($choice["logprobs"] ?? null) ? 'returned' : null;
            $tags["openai.response.choices.$idx.text"] = OpenAIIntegration::normalizeStringOrTokenArray($choice["text"] ?? "");

            $message = $choice['message'] ?? null;
            if ($message) {
                $tags["openai.response.choices.$idx.message.role"] = $message['role'] ?? null;
                $tags["openai.response.choices.$idx.message.content"] = OpenAIIntegration::normalizeStringOrTokenArray($message['content'] ?? "");
                $tags["openai.response.choices.$idx.message.name"] = OpenAIIntegration::normalizeStringOrTokenArray($message['name'] ?? "");
            }
        }

        return $tags;
    }

    public static function commonListCountResponseExtraction(array $payload): array
    {
        return [
            'openai.response.count' => \count($payload['data'] ?? [])
        ];
    }

    public static function createEmbeddingResponseExtraction(array $payload): array
    {
        $tags = OpenAIIntegration::usageExtraction($payload);

        $data = $payload['data'] ?? [];
        if (empty($data)) {
            return $tags;
        }

        $tags['openai.response.data.num-embeddings'] = \count($data);
        foreach ($data as $idx => $embedding) {
            $tags["openai.response.data.$idx.embedding-length"] = \count($embedding['embedding']);
        }

        return $tags;
    }

    public static function createRetrieveFileResponseExtraction(array $payload): array
    {
        return [
            'openai.response.filename' => $payload['filename'] ?? null,
            'openai.response.purpose' => $payload['purpose'] ?? null,
            'openai.response.bytes' => $payload['bytes'] ?? null,
            'openai.response.status' => $payload['status'] ?? null,
            'openai.response.status_details' => $payload['status_details'] ?? null,
        ];
    }

    public static function deleteFileResponseExtraction(array $payload): array
    {
        return [
            'openai.response.id' => $payload['id'] ?? null,
        ];
    }

    public static function downloadFileResponseExtraction(array $payload): array
    {
        return [
            'openai.response.total_bytes' => $payload['bytes'] ?? null,
        ];
    }

    public static function commonFineTuneResponseExtraction(array $payload): array
    {
        return [
            //'openai.response.events_count' => isset($payload['events']) ? \count($payload['events']) : null,
            'openai.response.fine_tuned_model' => $payload['fine_tuned_model'] ?? null,
            'openai.response.hyperparams.n_epochs' => $payload['hyperparams']['n_epochs'] ?? null,
            'openai.response.hyperparams.batch_size' => $payload['hyperparams']['batch_size'] ?? null,
            'openai.response.hyperparams.prompt_loss_weight' => $payload['hyperparams']['prompt_loss_weight'] ?? null,
            'openai.response.hyperparams.learning_rate_multiplier' => $payload['hyperparams']['learning_rate_multiplier'] ?? null,
            //'openai.response.training_files_count' => \count($)
            'openai.response.updated_at' => $payload['updated_at'] ?? null,
            'openai.response.status' => $payload['status'] ?? null,
        ];
    }

    public static function createAudioResponseExtraction(array $payload): array
    {
        return [
            'openai.response.text' => $payload['text'] ?? null,

            // Verbose JSON
            'openai.response.language' => $payload['language'] ?? null,
            'openai.response.duration' => $payload['duration'] ?? null,
            'openai.response.segments_count' => isset($payload['segments']) ? \count($payload['segments']) : null,
        ];
    }

    public static function commonImageResponseExtraction(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (empty($data)) {
            return [];
        }

        $tags = [
            'openai.response.images_count' => \count($data)
        ];

        foreach ($data as $idx => $image) {
            $tags["openai.response.images.$idx.url"] = OpenAIIntegration::normalizeStringOrTokenArray($image['url'] ?? '');
            $tags["openai.response.images.$idx.b64_json"] = isset($image['b64_json']) ? 'returned' : null;
        }

        return $tags;
    }

    public static function listModelsResponseExtraction(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (empty($data)) {
            return [];
        }

        return [
            'openai.response.count' => \count($data)
        ];
    }

    public static function retrieveModelResponseExtraction(array $payload): array
    {
        return [
            'openai.response.owned_by' => $payload['owned_by'] ?? null,
            'openai.response.parent' => $payload['parent'] ?? null,
            'openai.response.root' => $payload['root'] ?? null,
        ];
    }

    public static function usageExtraction(array $payload): array
    {
        return [
            'openai.response.usage.prompt_tokens' => $payload['usage']['prompt_tokens'] ?? null,
            'openai.response.usage.completion_tokens' => $payload['usage']['completion_tokens'] ?? null,
            'openai.response.usage.total_tokens' => $payload['usage']['total_tokens'] ?? null
        ];
    }

    public static function normalizeStringOrTokenArray(string|array|null $input): string|null
    {
        if (empty($input)) {
            return null;
        }

        if (\is_string($input)) {
            $input = \str_replace("\n", "\\n", $input);
            $input = \str_replace("\t", "\\t", $input);
        } else {
            $input = \json_encode($input);
        }

        $spanCharLimit = \dd_trace_env_config('DD_OPENAI_SPAN_CHAR_LIMIT');
        if (\strlen($input) > $spanCharLimit) {
            return \substr($input, 0, $spanCharLimit) . '...';
        }

        return $input;
    }

    // ---

    public static function handleStreamedResponse(
        SpanData        $span,
        ?DatadogLogger  $logger,
        array           $headers,
        \Generator      $response,
        string          $httpMethod,
        string          $endpoint,
    )
    {
        $methodName = \explode('/', $span->resource)[0];

        $tags = [
            'openai.request.endpoint' => $endpoint,
            'openai.request.method' => $httpMethod,
            'openai.organization.name' => $headers['openai-organization'] ?? null,
            'openai.response.model' => $headers['openai-model'] ?? null, // Specific model, often undefined
            'openai.response.id' => $headers['x-request-id'] ?? null, // Common creation value, numeric epoch
        ];

        switch ($methodName) {
            case 'createCompletion':
                $tags += OpenAIIntegration::commonStreamedCreateResponseExtraction($span, $response);
                break;
            case 'createChatCompletion':
                $tags += OpenAIIntegration::commonStreamedCreateChatResponseExtraction($span, $response);
                break;
        }

        foreach ($tags as $key => $value) {
            if (\is_null($value)) {
                continue;
            } elseif (\is_numeric($value)) {
                $span->metrics[$key] = $value;
            } else {
                $span->meta[$key] = $value;
            }
        }

        OpenAIIntegration::sendMetrics(
            span: $span,
            headers: $headers,
            duration: $span->getDuration(),
            promptTokens: $tags['openai.response.usage.prompt_tokens'] ?? 0,
            completionTokens: $tags['openai.response.usage.completion_tokens'] ?? 0
        );

        OpenAIIntegration::sendLog(
            logger: $logger,
            span: $span,
            methodName: $methodName,
            error: $span->exception ? true : false
        );
    }

    public static function commonStreamedCreateResponseExtraction(SpanData $span, \Generator $response): array
    {
        return self::commonStreamedResponseExtraction(
            $span,
            $response,
            fn($current) => self::estimateTokens($current['choices'][0]['text'] ?? '')
        );
    }

    public static function commonStreamedCreateChatResponseExtraction(SpanData $span, \Generator $response): array
    {
        return self::commonStreamedResponseExtraction(
            $span,
            $response,
            fn($current) => self::estimateTokens($current['choices'][0]['delta']['content'] ?? '')
        );
    }

    public static function commonStreamedResponseExtraction(SpanData $span, \Generator $response, callable $estimateTokens): array
    {
        $numCompletionTokens = 0;
        $numPromptTokens = $span->metrics['openai.response.usage.prompt_tokens'] ?? 0;

        try {
            while ($response->valid()) {
                $current = $response->current();
                $numCompletionTokens += $estimateTokens($current->toArray());
                $response->next();
            }
        } catch (\OpenAI\Exceptions\ErrorException $e) { // This is the error class that could be thrown by requestStream
            // If there was an error, it is THROWN by the generator
            $span->exception = $e;
        }

        return [
            'openai.response.completion_tokens_estimated' => 1,
            'openai.response.usage.completion_tokens' => $numCompletionTokens,
            'openai.response.usage.total_tokens' => $numPromptTokens + $numCompletionTokens
        ];
    }

    /**
     * Provide a very rough estimate of the number of tokens.
     * Approximate using the following assumptions:
     * 1 token ~= 4 chars
     * 1 token ~= ¾ words
     * @param string|array<int> $prompt
     * @return int
     */
    public static function estimateTokens(string|array $prompt): int
    {
        $estTokens = 0;
        if (is_string($prompt)) {
            $est1 = strlen($prompt) / 4;
            $est2 = preg_match_all('/[.,!?]/', $prompt) * 0.75;
            return round((1.5 * $est1 + 0.5 * $est2) / 2);
        } elseif (is_array($prompt) && is_int($prompt[0])) {
            return count($prompt);
        }
        return $estTokens;
    }
}
