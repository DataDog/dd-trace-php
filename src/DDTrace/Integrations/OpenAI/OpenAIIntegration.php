<?php

namespace DDTrace\Integrations\OpenAI;

use DataDog\DogStatsd;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use OpenAI\Responses\Completions\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;

class OpenAIIntegration extends Integration
{
    const NAME = 'openai';
    const USAGE_TOKENS = ['prompt', 'completion', 'total'];

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * @param SpanData $span The span to record the metric on
     * @param string $tag The tag to record the metric under
     * @param int|float $metric The metric to record
     * @return void
     */
    public static function setSpanMetric(SpanData $span, string $tag, int|float $metric): void
    {
        $span->metrics[$tag] = $metric;
    }

    /**
     * @param SpanData $span The span to retrieve the tags for
     * @return array{version: string, env: string, service: string, openai.request.model: string, openai.request.endpoint: string, openai.request.method: string, openai.organization.id: string, openai.organization.name: string, openai.user.api_key: string, error: string|null, error_type: string|null}
     */
    public static function getOpenAIMetricsTags(SpanData $span): array
    {
        $tags = [
            'version' => \dd_trace_env_config('DD_VERSION'),
            'env' => \dd_trace_env_config('DD_ENV'),
            'service' => \dd_trace_env_config('DD_SERVICE'),
            'openai.request.model' => $span->meta['openai.request.model'] ?? "",
            'openai.request.endpoint' => $span->meta['openai.request.endpoint'] ?? "",
            'openai.request.method' => $span->meta['openai.request.method'] ?? "",
            'openai.organization.id' => $span->meta['openai.organization.id'] ?? "",
            'openai.organization.name' => $span->meta['openai.organization.name'] ?? "",
            'openai.user.api_key' => $span->mmeta['openai.user.api_key'] ?? "",
            'error' => $span->exception?->getMessage()
        ];

        if ($span->exception) {
            $tags += [
                'error' => $span->exception->getMessage(),
                'error_type' => $span->exception::class
            ];
        }

        return $tags;
    }

    public static function sendMetric(DogStatsd $statsd, SpanData $span, string $kind, string $stat, int|float $value, array $tags = []): void
    {
        $tags += OpenAIIntegration::getOpenAIMetricsTags($span);
        switch ($kind) {
            case 'dist':
                $statsd->distribution(stat: $stat, value: $value, tags: $tags);
                break;
            case 'gauge':
                $statsd->gauge(stat: $stat, value: $value, tags: $tags);
                break;
            case 'increment':
                $statsd->increment(stats: $stat, value: $value, tags: $tags);
                break;
            default:
                // Unexpected metric type
                break;
        }
    }

    /**
     * @param SpanData $span The span to record usage on
     * @param array{prompt_tokens: int, completion_tokens: int|null, total_tokens: int}|null $usage The OpenAI usage data from the response
     * @return void
     */
    public static function recordUsage(DogStatsd $statsd, SpanData $span, ?array $usage)
    {
        if (empty($usage)) {
            return;
        }

        $tags = ["openai.estimated" => "false"];
        foreach (OpenAIIntegration::USAGE_TOKENS as $token_type) {
            if (isset($usage["{$token_type}_tokens"])) {
                $token = $usage["{$token_type}_tokens"];
                OpenAIIntegration::setSpanMetric($span, "openai.response.usage.{$token_type}_tokens", $token);
                OpenAIIntegration::sendMetric($statsd, $span, 'dist', "openai.tokens.$token_type", $token, $tags);
            }
        }

        OpenAIIntegration::sendMetric($statsd, $span, 'dist', 'openai.request.duration', $span->getDuration(), $tags);
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
        $integration = $this;
        $statsd = new DogStatsd([
            'datadog_host' => 'https://app.datadoghq.eu'
        ]);


        \DDTrace\hook_method(
            'OpenAI\Transporters\HttpTransporter',
            '__construct',
            function ($This, $scope, $args) {
                Logger::get()->debug("HttpTransporter::__construct");
                /** @var BaseUri $baseUri */
                $baseUri = $args[1];
                /** @var Headers $headers */
                $headers = $args[2];
                /** @var array<string, string> $data */
                $headers = $headers->toArray();

                $data = [];
                $data['base_url'] = $baseUri->toString();

                if (isset($headers['OpenAI-Organization'])) {
                    $data['organization'] = $headers['OpenAI-Organization'];
                }

                if (isset($headers['Authorization'])) {
                    $authorizationHeader = $headers['Authorization'];
                    $apiKey = \substr($authorizationHeader, 7); // Format: "Bearer <api_key>
                    $data['api_key'] = OpenAIIntegration::formatAPIKey($apiKey);
                }

                Logger::get()->debug('Putting data: ' . json_encode($data, JSON_PRETTY_PRINT));

                ObjectKVStore::put($This, 'data', $data);
            }
        );




        \DDTrace\hook_method(
            'OpenAI\Resources\Completions',
            '__construct',
            function ($This, $scope, $args) {
                Logger::get()->debug('Completions::__construct');
                $transporter = $args[0];
                $data = ObjectKVStore::get($transporter, 'data');
                ObjectKVStore::put($This, 'data', $data);
            }
        );

        \DDTrace\hook_method(
            'OpenAI\Responses\Completions\CreateResponse',
            'from',
            null,
            function ($This, $scope, $args, CreateResponse $response) {
                /** @var MetaInformation $meta */
                $meta = $args[1];

                ObjectKVStore::put($response, 'meta', $meta->toArray());
            });

        \DDTrace\trace_method('OpenAI\Resources\Completions', 'create', function (SpanData $span, array $args, ?CreateResponse $response) use ($integration, $statsd) {
            $span->name = 'openai.request';
            $span->resource = 'createCompletion';
            $span->meta[Tag::SPAN_TYPE] = Type::LLM;

            // Process the request
            $requestInformation = ObjectKVStore::get($this, 'data') ?? [];
            OpenAIIntegration::createCompletionRecordRequest($span, $args[0], $requestInformation);

            // Process the response
            /** @var array $meta */
            $meta = ObjectKVStore::get($response, 'meta') ?? [];
            OpenAIIntegration::extractMetaInformation($statsd, $span, $meta);
            OpenAIIntegration::createCompletionRecordResponse($span, $response->toArray());
            OpenAIIntegration::recordUsage($statsd, $span, $response?->usage?->toArray());
            OpenAIIntegration::setLLMTags($span, 'completion', $args[0], $response->toArray());
        });


        return Integration::LOADED;
    }

    /**
     * @param array|null $args The array of arguments passed to the function, if any. This should be an array of key-value pairs,
     * with the keys being the API request parameters and the values being the values of those parameters. This does not
     * have to represent all the values passed to the API request, only the ones that are relevant to the span and the
     * tags that will be added to it.
     * @param array|null $responseAttrs The response array returned by the OpenAI API request. This should be an array of
     * key-value pairs, with the keys being the API response attributes and the values being the values of those attributes.
     * @param array|null $baseTags The base tags to be added to the span. This should be an array of strings mapping to
     * `true`. These tags will be marked as `openai.<tagName>`, with the value being retrieved from the $args array. If
     * the tag doesn't exist in $args, it will be ignored.
     * @param string $endpointName The endpoint name of the OpenAI API request. It will be used to set the
     * `openai.request.endpoint` tag
     * @param string $httpMethodType The HTTP method type of the OpenAI API request. It will be used to set the
     * `openai.request.method` tag
     * @param string $resourceName The resource name to set the span resource to.
     * @return void
     */
    public static function endpointHook(
        SpanData $span,
        ?array   $args = [],
        ?array   $responseAttrs = [],
        ?array   $baseTags = ['api_base' => true, 'api_type' => true, 'api_version' => true],
        string   $endpointName = 'openai',
        string   $httpMethodType = "",
        string   $resourceName = ""
    )
    {

    }

    /**
     * @param SpanData $span
     * @param array $args
     * @param string $endpointName
     * @param string $httpMethodType
     * @param array $baseTags
     * @return void
     */
    public static function baseRecordRequest(
        SpanData $span,
        array    $args,
        string   $endpointName,
        string   $httpMethodType,
        array    $baseTags,
    )
    {
        $span->meta['openai.request.endpoint'] = "/v1/$endpointName";
        $span->meta['openai.request.method'] = $httpMethodType;

        foreach ($args as $arg => $value) {
            if (isset($baseTags[$arg])) {
                $span->meta["openai.$arg"] = $value;
            } elseif ($arg === "organization") {
                $span->meta['openai.organization.id'] = $value;
            } elseif ($arg === "api_key") {
                $span->meta['openai.user.api_key'] = OpenAIIntegration::formatAPIKey($value);
            } elseif ($arg === "engine") {
                $span->meta['openai.request.model'] = $value;
            } elseif (\is_array($value)) {
                foreach ($value as $subKey => $subVal) {
                    $span->meta["openai.request.$arg.$subKey"] = $subVal;
                }
            } else {
                $span->meta["openai.request.$arg"] = $value;
            }
        }
    }

    public static function baseRecordResponse(
        SpanData $span,
        array    $responseAttributes
    )
    {
        foreach ($responseAttributes as $key => $value) {
            $span->meta["openai.response.$key"] = $value;
        }
    }

    public static function extractMetaInformation(
        DogStatsd $statsd,
        SpanData  $span,
        array     $meta
    )
    {
        foreach ($meta as $key => $value) {
            switch ($key) {
                case 'openai-organization':
                    $span->meta['openai.organization.name'] = $value;
                    break;
                case 'x-ratelimit-limit-requests':
                    $span->metrics['openai.organization.ratelimit.requests.limit'] = $value;
                    OpenAIIntegration::sendMetric($statsd, $span, 'gauge', "openai.ratelimit.requests", $value);
                    break;
                case 'x-ratelimit-limit-tokens':
                    $span->metrics['openai.organization.ratelimit.tokens.limit'] = $value;
                    OpenAIIntegration::sendMetric($statsd, $span, 'gauge', "openai.ratelimit.tokens", $value);
                    break;
                case 'x-ratelimit-remaining-requests':
                    $span->metrics['openai.organization.ratelimit.requests.remaining'] = $value;
                    OpenAIIntegration::sendMetric($statsd, $span, 'gauge', "openai.ratelimit.remaining.requests", $value);
                    break;
                case 'x-ratelimit-remaining-tokens':
                    $span->metrics['openai.organization.ratelimit.tokens.remaining'] = $value;
                    OpenAIIntegration::sendMetric($statsd, $span, 'gauge', "openai.ratelimit.remaining.tokens", $value);
                    break;
            }
        }
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
        array $responseAttributes
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

    /** START COMPLETIONS **/

    public static function createCompletionRecordRequest(
        SpanData $span,
        array    $args,
        array    $requestInformation,
    )
    {
        $createCompletionArgsKeys = [
            'model' => true,
            'engine' => true,
            'suffix' => true,
            'max_tokens' => true,
            'temperature' => true,
            'top_p' => true,
            'n' => true,
            'stream' => true,
            'logprobs' => true,
            'echo' => true,
            'stop' => true,
            'presence_penalty' => true,
            'frequency_penalty' => true,
            'best_of' => true,
            'logit_bias' => true,
            'user' => true,
        ];

        $createCompletionArgs = \array_filter(
            $args,
            fn($key) => isset($createCompletionArgsKeys[$key]),
            ARRAY_FILTER_USE_KEY
        );

        OpenAIIntegration::baseRecordRequest(
            span: $span,
            args: $createCompletionArgs + $requestInformation,
            endpointName: 'completions',
            httpMethodType: 'POST',
            baseTags: ['api_base' => true, 'api_type' => true, 'api_version' => true],
        );

        $prompt = $args['prompt'] ?? [];
        $prompt = \is_string($prompt) ? [$prompt] : $prompt;
        foreach ($prompt as $idx => $value) {
            $span->meta["openai.request.prompt.$idx"] = OpenAIIntegration::processPrompt($value);
        }
    }

    public static function createCompletionRecordResponse(
        SpanData $span,
        array    $responseAttributes,
    )
    {
        $createCompletionResponseAttributesKeys = [
            'created' => true,
            'id' => true,
            'model' => true,
        ];

        $createCompletionResponseAttributes = \array_filter(
            $responseAttributes,
            fn($key) => isset($createCompletionResponseAttributesKeys[$key]),
            ARRAY_FILTER_USE_KEY
        );

        OpenAIIntegration::baseRecordResponse(
            $span,
            $createCompletionResponseAttributes
        );

        $choices = $responseAttributes['choices'] ?? [];
        foreach ($choices as $choice) {
            /** @var array{finish_reason: string, index: int, logprobs: object|null, text: string} $choice */
            $idx = $choice['index'];
            $span->meta["openai.response.choices.$idx.finish_reason"] = $choice['finish_reason'];
            $span->meta["openai.response.choices.$idx.text"] = OpenAIIntegration::processPrompt($choice['text']);
        }
    }

    /** END COMPLETIONS **/
}
