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

function mockClient(Response|ResponseInterface|string $response)
{
    $httpClient = Mockery::mock(ClientInterface::class);
    $apiKey = ApiKey::from('sk-88fc337ff7867d234728c5b3d2358977148cb8f35501b09d5d'); // This key is obviously fake
    $httpTransporter = new HttpTransporter(
        $httpClient,
        BaseUri::from('api.openai.com/v1'),
        Headers::withAuthorization($apiKey)->withContentType(ContentType::JSON),
        QueryParams::create()->withParam('foo', 'bar'),
        fn (RequestInterface $request): ResponseInterface => $httpClient->sendRequest($request)
    );

    $client = new Client($httpTransporter);

    $httpClient
        ->shouldReceive('sendRequest')
        ->andReturn($response);

    return $client;
}

/*
function mockContentClient(string $method, string $resource, array $params, string $response, bool $validateParams = true)
{
    return mockClient($method, $resource, $params, $response, 'requestContent', $validateParams);
}

function mockStreamClient(string $method, string $resource, array $params, ResponseInterface $response, bool $validateParams = true)
{
    return mockClient($method, $resource, $params, $response, 'requestStream', $validateParams);
}
*/
