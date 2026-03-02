<?php

namespace DDTrace\Tests\Integrations\OpenAI;

function response(): array
{
    return [
        'id' => 'resp-fake-internal',
        'object' => 'response',
        'created_at' => 1677652288,
        'status' => 'completed',
        'model' => 'gpt-3.5-turbo-0125',
        // Fields commonly expected by the OpenAI PHP client for Responses API
        'error' => null,
        'incomplete_details' => null,
        'instructions' => 'You are a helpful assistant.',
        'max_output_tokens' => null,
        'output' => [
            [
                'type' => 'message',
                'id' => 'msg-fake-internal',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => 'Fake response from internal_server mock.',
                        'annotations' => [],
                    ],
                ],
            ],
        ],
        'output_text' => 'Fake response from internal_server mock.',
        'previous_response_id' => null,
        'parallel_tool_calls' => false,
        'tool_choice' => 'none',
        'tools' => [],
        'store' => true,
        'reasoning' => [
            'effort' => null,
            'summary' => null,
        ],
        'temperature' => 1.0,
        'text' => [
            'format' => [
                'type' => 'text',
            ],
        ],
        'top_p' => 1.0,
        'truncation' => 'disabled',
        'user' => null,
        'metadata' => [],
        'usage' => [
            'input_tokens' => 1,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens' => 2,
            'output_tokens_details' => ['reasoning_tokens' => 0],
            'total_tokens' => 3,
        ],
    ];
}

/**
 * @return resource
 */
function responseStream()
{
    return fopen(__DIR__.'/Streams/ResponseCreate.txt', 'r');
}