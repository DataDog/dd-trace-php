<?php

namespace DDTrace\Integrations\OpenAI;

use DataDog\DogStatsd;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use OpenAI\Responses\Completions\CreateResponse;

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
    private static function setSpanMetric(SpanData $span, string $tag, int|float $metric): void
    {
        $span->metrics[$tag] = $metric;
    }

    /**
     * @param SpanData $span The span to retrieve the tags for
     * @return array{version: string, env: string, service: string, openai.request.model: string, openai.request.endpoint: string, openai.request.method: string, openai.organization.id: string, openai.organization.name: string, openai.user.api_key: string, error: string|null, error_type: string|null}
     */
    private static function getOpenAIMetricsTags(SpanData $span): array
    {
        $tags = [
            'version'   => \dd_trace_env_config('DD_VERSION'),
            'env'       => \dd_trace_env_config('DD_ENV'),
            'service'   => \dd_trace_env_config('DD_SERVICE'),
            'openai.request.model'      => $span->meta['openai.request.model'] ?? "",
            'openai.request.endpoint'   => $span->meta['openai.request.endpoint'] ?? "",
            'openai.request.method'     => $span->meta['openai.request.method'] ?? "",
            'openai.organization.id'    => $span->meta['openai.organization.id'] ?? "",
            'openai.organization.name'  => $span->meta['openai.organization.name'] ?? "",
            'openai.user.api_key'       => $span->mmeta['openai.user.api_key'] ?? "",
            'error'                     => $span->exception?->getMessage()
        ];

        if ($span->exception) {
            $tags += [
                'error'         => $span->exception->getMessage(),
                'error_type'    => $span->exception::class
            ];
        }

        return $tags;
    }

    private static function sendMetric(DogStatsd $statsd, SpanData $span, string $kind, string $stat, int|float $value, array $tags = []): void
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
    private static function recordUsage(DogStatsd $statsd, SpanData $span, ?array $usage)
    {
        if (empty($usage)) {
            return;
        }

        $tags = ["openai.estimated" => "false"];
        foreach (OpenAIIntegration::USAGE_TOKENS as $token_type) {
            if (isset($usage["{$token_type}_tokens"])) {
                OpenAIIntegration::setSpanMetric($span, "openai.response.usage.{$token_type}_tokens", $usage[$token_type]);
                OpenAIIntegration::sendMetric($statsd, $span, 'dist', "tokens.$token_type", $usage[$token_type], $tags);
            }
        }
    }

    private static function processPrompt(string $prompt): string
    {
        if (empty($prompt)) {
            return $prompt;
        }

        $prompt = \str_replace("\n", "\\n", $prompt);
        $prompt = \str_replace("\t", "\\t", $prompt);

        $spanCharLimit = \dd_trace_env_config('DD_OPENAI_SPAN_CHAR_LIMIT');
        if (\strlen($prompt) > $spanCharLimit) {
            return \substr($prompt, 0, $spanCharLimit) . '...';
        }

        return $prompt;
    }

    /**
     * Add instrumentation to OpenAI API Requests
     */
    public function init(): int
    {
        $integration = $this;
        $statsd = new DogStatsd();

        \DDTrace\trace_method('OpenAI\Resources\Completions', 'create', function (SpanData $span, array $args, CreateResponse $response) use ($integration, $statsd) {
            $span->name = 'openai.request';
            $span->resource = 'createCompletion';

            $span->meta[Tag::SPAN_TYPE] = Type::LLM;

            // Process the request
            $prompt = $args['prompt'] ?? null;
            if ($prompt) {
                $prompt = \is_string($prompt) ? [$prompt] : $prompt;

                foreach ($prompt as $idx => $value) {
                    $span->meta["openai.request.prompt.$idx"] = OpenAIIntegration::processPrompt($value);
                }
            }

            // Process the response
            OpenAIIntegration::recordUsage($statsd, $span, $response->usage->toArray());
        });

        return Integration::LOADED;
    }
}
