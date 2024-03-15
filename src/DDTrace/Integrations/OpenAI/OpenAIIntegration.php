<?php

namespace DDTrace\Integrations\OpenAI;

use DataDog\DogStatsd;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseHasMetaInformationContract;
use OpenAI\Transporters\HttpTransporter;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;

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
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public static function processPrompt(string $prompt): string
    {
        if (empty($prompt)) {
            return $prompt;
        }

        $prompt = \str_replace("\n", "\\n", $prompt);
        $prompt = \str_replace("\t", "\\t", $prompt);

        $spanCharLimit = 128;//\dd_trace_env_config('DD_OPENAI_SPAN_CHAR_LIMIT');
        if (\strlen($prompt) > $spanCharLimit) {
            return \substr($prompt, 0, $spanCharLimit) . '...';
        }

        return $prompt;
    }

    /**
     * @param string $apiKey The OpenAI API key to format
     * @return string The formatted API key 'XXX...YYYY', where XXX and YYYY are respectively the first three and last
     * four characters of the provided OpenAI API Key
     */
    public static function formatAPIKey(string $apiKey): string
    {
        return \substr($apiKey, 0, 3) . '...' . \substr($apiKey, -4);
    }

    /**
     * Add instrumentation to OpenAI API Requests
     */
    public function init(): int
    {
        $statsd = new DogStatsd([
            'datadog_host' => 'https://app.datadoghq.eu'
        ]);

        $targets = [
            'OpenAI\Resources\Completions::create' => ['createCompletion', 'POST', '/v1/completions'],
            'OpenAI\Resources\Chat::create' => ['createChatCompletion', 'POST', '/v1/chat/completions'],
            'OpenAI\Resources\Embeddings::create' => ['createEmbedding', 'POST', '/v1/embeddings'],
            'OpenAI\Resources\Models::list' => ['listModels', 'GET', '/v1/models'],
            'OpenAI\Resources\Files::list' => ['listFiles', 'GET', '/v1/files'],
            'OpenAI\Resources\FineTunes::list' => ['listFineTunes', 'GET', '/v1/fine-tunes'],
            'OpenAI\Resources\Models::retrieve' => ['retrieveModel', 'GET', '/v1/models/*'],
            'OpenAI\Resources\Files::retrieve' => ['retrieveFile', 'GET', '/v1/files/*'],
            'OpenAI\Resources\FineTunes::retrieve' => ['retrieveFineTune', 'GET', '/v1/fine-tunes/*'],
            'OpenAI\Resources\Models::delete' => ['deleteModel', 'DELETE', '/v1/models/*'],
            'OpenAI\Resources\Files::delete' => ['deleteFile', 'DELETE', '/v1/files/*'],
            'OpenAI\Resources\Edits::create' => ['createEdit', 'POST', '/v1/edits'],
            'OpenAI\Resources\Images::create' => ['createImage', 'POST', '/v1/images/generations'],
            'OpenAI\Resources\Images::edit' => ['createImageEdit', 'POST', '/v1/images/edits'],
            'OpenAI\Resources\Images::variation' => ['createImageVariation', 'POST', '/v1/images/variations'],
            'OpenAI\Resources\Audio::transcribe' => ['createTranscription', 'POST', '/v1/audio/transcriptions'],
            'OpenAI\Resources\Audio::translate' => ['createTranslation', 'POST', '/v1/audio/translations'],
            'OpenAI\Resources\Moderations::create' => ['createModeration', 'POST', '/v1/moderations'],
            'OpenAI\Resources\Files::upload' => ['createFile', 'POST', '/v1/files'],
            'OpenAI\Resources\Files::download' => ['downloadFile', 'GET', '/v1/files/*/content'],
            'OpenAI\Resources\FineTunes::create' => ['createFineTune', 'POST', '/v1/fine-tunes'],
            'OpenAI\Resources\FineTunes::cancel' => ['cancelFineTune', 'POST', '/v1/fine-tunes/*/cancel'],
            'OpenAI\Resources\FineTunes::listEvents' => ['listFineTuneEvents', 'GET', '/v1/fine-tunes/*/events'],
        ];

        \DDTrace\hook_method(
            'OpenAI\Transporters\HttpTransporter',
            '__construct',
            function ($This, $scope, $args) {
                /** @var BaseUri $baseUri */
                $baseUri = $args[1];
                /** @var Headers $headers */
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

        foreach ($targets as $target => [$methodName, $httpMethod, $endpoint]) {
            \DDTrace\install_hook(
                $target,
                function (\DDTrace\HookData $hook) use ($methodName) {
                    $span = $hook->span();
                    $args = $hook->args;

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
                function (\DDTrace\HookData $hook) use ($statsd, $httpMethod, $endpoint) {
                    /** @var ResponseContract&ResponseHasMetaInformationContract $returned */
                    $response = $hook->returned;
                    $meta = $response->meta();

                    OpenAIIntegration::handleResponse(
                        statsd: $statsd,
                        headers: $meta->toArray(),
                        response: $response->toArray(),
                        httpMethod: $httpMethod,
                        endpoint: $endpoint,
                        args: $hook->args,
                    );
                }
            );
        }

        return Integration::LOADED;
    }

    /** START LLM OBSERVABILITY **/

    public static function setLLMTags(
        SpanData $span,
        string   $recordType,
        array    $arguments,
        array    $responseAttributes
    )
    {
        $span->meta[Tag::SPAN_KIND] = Type::LLM;

        $modelName = $span->meta['openai.response.model'] ?? $span->meta['openai.request.model'] ?? "";
        $span->meta[Tag::LLMOBS_MODEL_NAME] = $modelName;
        $span->meta[Tag::LLMOBS_MODEL_PROVIDER] = Type::OPENAI;

        if ($recordType === 'completion') {
            OpenAIIntegration::setLLMMetaFromCompletion($span, $arguments, $responseAttributes);
        } elseif ($recordType === 'chat') {
            OpenAIIntegration::setLLMMetaFromChat($span, $arguments, $responseAttributes);
        }

        OpenAIIntegration::setLLMMetrics($span, $responseAttributes);
    }

    public static function setLLMMetaFromCompletion(
        SpanData $span,
        array    $arguments,
        array    $responseAttributes,
    )
    {
        $prompt = $args['prompt'] ?? [];
        $prompt = \is_string($prompt) ? [$prompt] : $prompt;
        $span->meta[Tag::LLMOBS_INPUT_MESSAGES] = \json_encode(\array_map(fn($p) => ["content" => $p], $prompt));

        $parameters = [
            'temperature' => $arguments['temperature'] ?? 0,
        ];
        if (isset($arguments['max_tokens'])) {
            $parameters['max_tokens'] = $arguments['max_tokens'];
        }
        $span->meta[Tag::LLMOBS_INPUT_PARAMETERS] = \json_encode($parameters);

        // TODO: Handle Streamed Responses and Errors
        if ($responseAttributes['choices']) {
            $span->meta[Tag::LLMOBS_OUTPUT_MESSAGES] = \json_encode(
                \array_map(
                    fn($choice) => ["context" => $choice['text']],
                    $responseAttributes['choices']
                )
            );
        } else {
            $span->meta[Tag::LLMOBS_OUTPUT_MESSAGES] = \json_encode(["content" => ""]);
        }
    }

    public static function setLLMMetaFromChat(
        SpanData $span,
        array    $arguments,
        array    $responseAttributes
    )
    {

    }

    public static function setLLMMetrics(
        SpanData $span,
        array    $responseAttributes
    )
    {
        // TODO: Handle Streamed
        $usage = $responseAttributes['usage'];
        $metrics = [
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'total_tokens' => $usage['prompt_tokens'] + $usage['completion_tokens']
        ];

        $span->meta[Tag::LLMOBS_METRICS] = \json_encode($metrics);
    }

    /** END LLM OBSERVABILITY **/

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

            // TODO: Handle Streamed Version
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
        string   $apiKey
    )
    {
        $payload = OpenAIIntegration::normalizeRequestPayload($methodName, $args);

        $span->name = 'openai.request';
        $span->resource = isset($payload['model']) ? $methodName . '/' . $payload['model'] : $methodName;
        $span->type = Type::OPENAI;
        $span->kind = Tag::SPAN_KIND_VALUE_CLIENT;
        $span->meta['openai.user.api_key'] = $apiKey;
        $span->meta['openai.api_base'] = $basePath;
        $span->metrics['_dd.measured'] = 1;

        foreach ($payload as $key => $value) {
            if (isset(OpenAIIntegration::COMMON_PARAMETERS[$key]) && !\is_null($value)) {
                $span->meta["openai.request.$key"] = $value;
            }
        }

        // createChatCompletion, createCompletion, createImage, createImageEdit, createTranscription, createTranslation
        if (array_key_exists('prompt', $payload)) {
            $prompt = $payload['prompt'];
            if (\is_string($prompt)) {
                $span->meta["openai.request.prompt"] = OpenAIIntegration::normalizeStringOrTokenArray($prompt);
            } else {
                foreach ($prompt as $idx => $value) {
                    $span->meta["openai.request.prompt.$idx"] = OpenAIIntegration::normalizeStringOrTokenArray($value);
                }
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

        $tags = [];
        switch ($methodName) {
            case 'createFineTune':
                $tags = OpenAIIntegration::createFineTuneRequestExtraction($payload);
                break;

            case 'createImage':
            case 'createImageEdit':
            case 'createImageVariation':
                OpenAIIntegration::commonCreateImageRequestExtraction($payload);
                break;

            case 'createChatCompletion':
                OpenAIIntegration::createChatCompletionRequestExtraction($payload);
                break;

            case 'createFile':
            case 'retrieveFile':
                OpenAIIntegration::commonFileRequestExtraction($payload);

            case 'createTranscription':
            case 'createTranslation':
                OpenAIIntegration::commonCreateAudioRequestExtraction($payload);
                break;

            case 'retrieveModel':
                OpenAIIntegration::retrieveModelRequestExtraction($payload);
                break;

            case 'listFineTuneEvents':
            case 'retrieveFineTune':
            case 'deleteModel':
            case 'cancelFineTune':
                OpenAIIntegration::commonLookupFineTuneRequestExtraction($payload);
                break;

            case 'createEdit':
                OpenAIIntegration::createEditRequestExtraction($payload);
                break;
        }

        foreach ($tags as $key => $value) {
            if (!\is_null($value)) {
                $span->meta[$key] = $value;
            }
        }
    }

    public static function handleResponse(
        DogStatsd $statsd,
        array     $headers,
        array     $response,
        string    $httpMethod,
        string    $endpoint,
        array     $args,
    )
    {
        $span = \DDTrace\active_span();
        $methodName = \explode('/', $span->resource)[0];

        if ($methodName === 'downloadFile') {
            $response = ['file' => $response];
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
            if (!\is_null($value)) {
                $span->meta[$key] = $value;
            }
        }

        \DDTrace\close_span();

        $logTags = OpenAIIntegration::getLogTags(
            span: $span,
            methodName: $methodName
        );

        OpenAIIntegration::sendMetrics(
            span: $span,
            statsd: $statsd,
            headers: $headers,
            response: $response,
            duration: $span->getDuration()
        );
        /*
        if ($methodName === 'createCompletion') {
            OpenAIIntegration::setLLMTags(
                span: $span,
                recordType: 'completion',
                arguments: $args[0],
                responseAttributes: $response
            );
        } elseif ($methodName === 'createChatCompletion') {
            OpenAIIntegration::setLLMTags(
                span: $span,
                recordType: 'chat',
                arguments: $args[0],
                responseAttributes: $response
            );
        }
        */
        OpenAIIntegration::sendLog(
            span: $span,
            methodName: $methodName,
            tags: $logTags
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
            'service' => \dd_trace_env_config('DD_SERVICE') ?? $span->service,
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
                break;

        }

        return $tags;
    }

    public static function sendLog(
        SpanData $span,
        string   $methodName,
        array    $tags,
        bool     $error = false
    )
    {
        $logger = Logger::get(true, true);
        $logMethod = $error ? 'error' : 'info';
        $logMessage = "sampled $methodName";
        $logger->$logMethod($logMessage, $tags);
    }

    public
    static function sendMetrics(
        SpanData  $span,
        DogStatsd $statsd,
        array     $headers,
        array     $response,
        int       $duration,
    )
    {
        $tags = [
            'env' => \dd_trace_env_config('DD_ENV'),
            'service' => \dd_trace_env_config('DD_SERVICE') ?? $span->service,
            'version' => \dd_trace_env_config('DD_VERSION'),
            'openai.request.model' => $span->meta['openai.request.model'] ?? null,
            'model' => $span->meta['openai.request.model'] ?? null,
            'openai.organization.id' => $span->meta['openai.organization.id'] ?? null,
            'openai.organization.name' => $span->meta['openai.organization.name'] ?? null,
            'openai.user.api_key' => $span->meta['openai.user.api_key'] ?? null,
            'openai.request.endpoint' => $span->meta['openai.request.endpoint'] ?? null,
            'openai.estimated' => false,
            'openai.request.error' => 0,
        ];

        $statsd->distribution(
            stat: 'openai.request.duration',
            value: $duration,  // Duration is in ns
            tags: $tags
        );
        $statsd->distribution(
            stat: 'trace.openai.request.duration',
            value: $duration / 1e9, // Duration in seconds
            tags: $tags
        );
        $statsd->increment(
            stats: 'openai.request.error',
            tags: $tags
        );

        $usage = $response['usage'] ?? null;
        if ($usage) {
            $promptTokens = (int)$usage['prompt_tokens'];
            $completionTokens = (int)$usage['completion_tokens'];
            $statsd->distribution(
                stat: 'openai.tokens.prompt',
                value: $promptTokens,
                tags: $tags
            );
            $statsd->distribution(
                stat: 'openai.tokens.completion',
                value: $completionTokens,
                tags: $tags
            );
            $statsd->distribution(
                stat: 'openai.tokens.total',
                value: $promptTokens + $completionTokens,
                tags: $tags
            );
        }

        if (isset($headers['x-ratelimit-limit-requests'])) {
            $statsd->gauge(
                stat: 'openai.ratelimit.requests',
                value: (int)$headers['x-ratelimit-limit-requests'],
                tags: $tags
            );
        }

        if (isset($headers['x-ratelimit-limit-tokens'])) {
            $statsd->gauge(
                stat: 'openai.ratelimit.tokens',
                value: (int)$headers['x-ratelimit-limit-tokens'],
                tags: $tags
            );
        }

        if (isset($headers['x-ratelimit-remaining-requests'])) {
            $statsd->gauge(
                stat: 'openai.ratelimit.remaining.requests',
                value: (int)$headers['x-ratelimit-remaining-requests'],
                tags: $tags
            );
        }

        if (isset($headers['x-ratelimit-remaining-tokens'])) {
            $statsd->gauge(
                stat: 'openai.ratelimit.remaining.tokens',
                value: (int)$headers['x-ratelimit-remaining-tokens'],
                tags: $tags
            );
        }
    }

    public
    static function createFineTuneRequestExtraction(array $payload): array
    {
        return [
            'openai.request.training_file' => $payload['training_file'] ?? null,
            'openai.request.validation_file' => $payload['validation_file'] ?? null,
            'openai.request.n_epochs' => $payload['hyperparameters']['n_epochs'] ?? null,
            'openai.request.batch_size' => $payload['hyperparameters']['batch_size'] ?? null,
            'openai.request.learning_rate_multiplier' => $payload['hyperparameters']['learning_rate_multiplier'] ?? null,
        ];
    }

    public
    static function commonCreateImageRequestExtraction(array $payload): array
    {
        return [
            'openai.request.image' => $payload['image'] ?? null,
            'openai.request.mask' => $payload['mask'] ?? null,
            'openai.request.size' => $payload['size'] ?? null,
            'openai.request.response_format' => $payload['response_format'] ?? null,
            'openai.request.language' => $payload['language'] ?? null,
        ];
    }

    public
    static function createChatCompletionRequestExtraction(array $payload): array
    {
        $messages = $payload['messages'] ?? [];

        $tags = [];
        foreach ($messages as $idx => $message) {
            $tags["openai.request.$idx.content"] = $message['content'] ?? null;
            $tags["openai.request.$idx.role"] = $message['role'] ?? null;
            $tags["openai.request.$idx.name"] = $message['name'] ?? null;
            $tags["openai.request.$idx.finish_reason"] = $message['finish_reason'] ?? null;
        }

        return $tags;
    }

    public
    static function commonFileRequestExtraction(array $payload): array
    {
        return [
            'openai.request.purpose' => $payload['purpose'] ?? null,
            'openai.request.filename' => $payload['file'] ?? null,
        ];
    }

    public
    static function commonCreateAudioRequestExtraction(array $payload): array
    {
        return [
            'openai.request.response_format' => $payload['response_format'] ?? null,
            'openai.request.language' => $payload['language'] ?? null,
            'openai.request.filename' => $payload['file'] ?? null,
        ];
    }

    public
    static function retrieveModelRequestExtraction(array $payload): array
    {
        return [
            'openai.request.id' => $payload['id'] ?? null,
        ];
    }

    public
    static function commonLookupFineTuneRequestExtraction(array $payload): array
    {
        return [
            'openai.request.fine_tune_id' => $payload['fine_tune_id'] ?? null,
            'openai.request.stream' => $payload['stream'] ?? null,
        ];
    }

    public
    static function createEditRequestExtraction(array $payload): array
    {
        return [
            'openai.request.instruction' => $payload['instruction'] ?? null,
        ];
    }

    public
    static function createModerationResponseExtraction(array $payload): array
    {
        $tags = [
            'openai.response.id' => $payload['id'] ?? null,
        ];

        if (empty($payload['results'])) {
            return $tags;
        }

        $tags['openai.response.flagged'] = $payload['results']['flagged'];

        foreach ($payload['results']['categories'] as $category => $flag) {
            $tags["openai.response.categories.$category"] = $flag;
        }

        foreach ($payload['results']['category_scores'] as $category_score => $score) {
            $tags["openai.response.category_scores.$category_score"] = $score;
        }

        return $tags;
    }

    public
    static function commonCreateResponseExtraction(array $payload): array
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

    public
    static function commonListCountResponseExtraction(array $payload): array
    {
        return [
            'openai.response.count' => \count($payload['data'] ?? [])
        ];
    }

    public
    static function createEmbeddingResponseExtraction(array $payload): array
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

    public
    static function createRetrieveFileResponseExtraction(array $payload): array
    {
        return [
            'openai.response.filename' => $payload['filename'] ?? null,
            'openai.response.purpose' => $payload['purpose'] ?? null,
            'openai.response.bytes' => $payload['bytes'] ?? null,
            'openai.response.status' => $payload['status'] ?? null,
            'openai.response.status_details' => $payload['status_details'] ?? null,
        ];
    }

    public
    static function deleteFileResponseExtraction(array $payload): array
    {
        return [
            'openai.response.id' => $payload['id'] ?? null,
        ];
    }

    public
    static function downloadFileResponseExtraction(array $payload): array
    {
        return [
            'openai.response.total_bytes' => $payload['bytes'] ?? null,
        ];
    }

    public
    static function commonFineTuneResponseExtraction(array $payload): array
    {
        return [
            //'openai.response.events_count' => isset($payload['events']) ? \count($payload['events']) : null,
            'openai.response.fine_tuned_model' => $payload['fine_tuned_model'] ?? null,
            'openai.response.hyperparams.n_epochs' => $payload['hyperparameters']['n_epochs'] ?? null,
            'openai.response.hyperparams.batch_size' => $payload['hyperparameters']['batch_size'] ?? null,
            'openai.response.hyperparams.prompt_loss_weight' => $payload['hyperparameters']['prompt_loss_weight'] ?? null,
            'openai.response.hyperparams.learning_rate_multiplier' => $payload['hyperparameters']['learning_rate_multiplier'] ?? null,
            //'openai.response.training_files_count' => \count($)
            'openai.response.updated_at' => $payload['updated_at'],
            'openai.response.status' => $payload['status'],
        ];
    }

    public
    static function createAudioResponseExtraction(array $payload): array
    {
        return [
            'openai.response.text' => $payload['text'] ?? null,
            'openai.response.language' => $payload['language'] ?? null,
            'openai.response.duration' => $payload['duration'] ?? null,
            'openai.response.segments_count' => \count($payload['segments_count'] ?? []),
        ];
    }

    public
    static function commonImageResponseExtraction(array $payload): array
    {
        $data = $payload['data'];
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

    public
    static function listModelsResponseExtraction(array $payload): array
    {
        $data = $payload['data'];
        if (empty($data)) {
            return [];
        }

        return [
            'openai.response.count' => \count($data)
        ];
    }

    public
    static function retrieveModelResponseExtraction(array $payload): array
    {
        return [
            'openai.response.owned_by' => $payload['owned_by'] ?? null,
            'openai.response.parent' => $payload['parent'] ?? null,
            'openai.response.root' => $payload['root'] ?? null,
        ];
    }

    public
    static function usageExtraction(array $payload): array
    {
        return [
            'openai.response.usage.prompt_tokens' => $payload['usage']['prompt_tokens'] ?? null,
            'openai.response.usage.completion_tokens' => $payload['usage']['completion_tokens'] ?? null,
            'openai.response.usage.total_tokens' => $payload['usage']['total_tokens'] ?? null
        ];
    }

    /**
     * @param string|array $input
     * @return string
     */
    public
    static function normalizeStringOrTokenArray(string|array $input): string
    {
        if (empty($input)) {
            return "";
        }

        if (\is_string($input)) {
            $input = \str_replace("\n", "\\n", $input);
            $input = \str_replace("\t", "\\t", $input);
        } else {
            $input = \json_encode($input);
        }

        $spanCharLimit = 128;//\dd_trace_env_config('DD_OPENAI_SPAN_CHAR_LIMIT');
        if (\strlen($input) > $spanCharLimit) {
            return \substr($input, 0, $spanCharLimit) . '...';
        }

        return $input;
    }
}
