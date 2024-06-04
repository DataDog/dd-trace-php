<?php

namespace DDTrace\Tests\Integrations\OpenAI;

use Mockery;
use OpenAI\Client;
use OpenAI\Contracts\TransporterContract;
use OpenAI\Enums\Transporter\ContentType;
use OpenAI\Transporters\HttpTransporter;
use OpenAI\ValueObjects\ApiKey;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;
use OpenAI\ValueObjects\Transporter\Payload;
use OpenAI\ValueObjects\Transporter\QueryParams;
use OpenAI\ValueObjects\Transporter\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

function mockClient($response)
{
    $httpClient = Mockery::mock(ClientInterface::class);
    $apiKey = ApiKey::from('sk-88fc337ff7867d234728c5b3d2358977148cb8f35501b09d5d'); // This key is obviously fake
    $httpTransporter = new HttpTransporter(
        $httpClient,
        BaseUri::from('api.openai.com/v1'),
        Headers::withAuthorization($apiKey)->withContentType(ContentType::JSON),
        QueryParams::create()->withParam('foo', 'bar'),
        function (RequestInterface $request) use ($httpClient): ResponseInterface {
            return $httpClient->sendRequest($request);
        }
    );

    $client = new Client($httpTransporter);

    $httpClient
        ->shouldReceive('sendRequest')
        ->andReturn($response);

    return $client;
}

function invalidAPIKeyProvided(): array
{
    return [
        'error' => [
            'message' => 'Incorrect API key provided: foo. You can find your API key at https://platform.openai.com.',
            'type' => 'invalid_request_error',
            'param' => null,
            'code' => 'invalid_api_key',
        ],
    ];
}

function errorMessageArray(): array
{
    return [
        'error' => [
            'message' => [
                'Invalid schema for function \'get_current_weather\':',
                'In context=(\'properties\', \'location\'), array schema missing items',
            ],
            'type' => 'invalid_request_error',
            'param' => null,
            'code' => null,
        ],
    ];
}

function nullErrorType(): array
{
    return [
        'error' => [
            'message' => 'You exceeded your current quota, please check',
            'type' => null,
            'param' => null,
            'code' => 'quota_exceeded',
        ],
    ];
}
