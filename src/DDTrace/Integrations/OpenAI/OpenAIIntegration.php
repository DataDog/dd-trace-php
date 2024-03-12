<?php

namespace DDTrace\Integrations\OpenAI;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use OpenAI\Responses\Completions\CreateResponse;

class OpenAIIntegration extends Integration
{
    const NAME = 'openai';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to OpenAI API Requests
     */
    public function init(): int
    {
        $integration = $this;

        \DDTrace\trace_method('OpenAI\Resources\Completions', 'create', function (SpanData $span, array $args, CreateResponse $response) {

        });

        return Integration::LOADED;
    }
}
