<?php

namespace DDTrace\Tests\Integrations\OpenAI;

/**
 * @return array<string, mixed>
 */
function chatCompletion(): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'created' => 1677652288,
        'model' => 'gpt-3.5-turbo',
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => "\n\nHello there, how may I assist you today?",
                ],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 9,
            'completion_tokens' => 12,
            'total_tokens' => 21,
        ],
    ];
}

function chatCompletionDefaultExample(): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'created' => 1677652288,
        'model' => 'gpt-3.5-turbo-0125',
        'system_fingerprint' => 'fp_44709d6fcb',
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => "\n\nHello there, how may I assist you today?",
                ],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 9,
            'completion_tokens' => 12,
            'total_tokens' => 21,
        ],
    ];
}

function chatCompletionFromImageInput(): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'created' => 1677652288,
        'model' => 'gpt-3.5-turbo-0125',
        'system_fingerprint' => 'fp_44709d6fcb',
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => "\n\nThis image shows a wooden boardwalk extending through a lush green marshland.",
                ],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 9,
            'completion_tokens' => 12,
            'total_tokens' => 21,
        ],
    ];
}


function chatCompletionWithFunctions(): array
{
    return [
        'id' => 'chatcmpl-abc123',
        'object' => 'chat.completion',
        'created' => 1699896916,
        'model' => 'gpt-3.5-turbo-0125',
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => 'call_abc123',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_current_weather',
                                'arguments' => "{\n\"location\": \"Boston, MA\"\n}",
                            ],
                        ],
                    ],
                ],
                'logprobs' => null,
                'finish_reason' => 'tool_calls',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 82,
            'completion_tokens' => 17,
            'total_tokens' => 99,
        ],
    ];
}

/**
 * @return resource
 */
function chatCompletionStream()
{
    return fopen(__DIR__.'/Streams/ChatCompletionCreate.txt', 'r');
}

/**
 * @return resource
 */
function chatCompletionStreamError()
{
    return fopen(__DIR__.'/Streams/ChatCompletionCreateError.txt', 'r');
}
