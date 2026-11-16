<?php

/**
 * GET /llm - LLM endpoint for system-tests.
 * Query params: model (e.g. gpt-4.1), operation (e.g. openai-latest-responses.create).
 * Uses openai-php/client; configure OPENAI_BASE_URL to point at the mock (e.g. http://internal_server:8089/v1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$model = $_GET['model'] ?? null;
$operation = $_GET['operation'] ?? null;

if ($model === null || $model === '' || $operation === null || $operation === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or empty query parameters: model, operation']);
    exit;
}

try {
    $baseUri = getenv('OPENAI_BASE_URL') ?: 'http://localhost/mockOpenAi/';
    $client = \OpenAI::factory()
        ->withApiKey('sk-fake')
        ->withBaseUri($baseUri)
        ->make();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI client init failed: ' . $e->getMessage()]);
    exit;
}

$params = ['model' => $model];

try {
    switch ($operation) {
        case 'openai-latest-responses.create':
            $params['input'] = 'Hello';
            $response = $client->responses()->create($params);
            break;
        case 'openai-latest-chat.completions.create':
            $params['messages'] = [['role' => 'user', 'content' => 'Hello']];
            $response = $client->chat()->create($params);
            break;
        case 'openai-latest-completions.create':
            $params['prompt'] = 'Hello';
            $response = $client->completions()->create($params);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown operation: ' . $operation]);
            exit;
    }

    // Return a simple success payload; the client returns response objects
    echo json_encode([
        'model' => $model,
        'operation' => $operation,
        'status' => 'ok',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'operation' => $operation,
    ]);
}