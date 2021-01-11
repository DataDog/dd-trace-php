<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/ddtrace.php';
instrumentMethod('DistributedTracingMulti', 'fetchUsersFromApi');

class DistributedTracingMulti
{
    // Tests distributed tracing with multi-exec
    public function fetchUsersFromApi($groups)
    {
        $curl = new CurlMultiHandler();
        $client = new Client([
            'handler' => HandlerStack::create($curl)
        ]);

        $users = [];
        $resolver = function (Response $response) use (&$users) {
            $content = $response->getBody();
            $users[] = json_decode($content, 1);
            echo 'Downloaded content (' . strlen($content) . ' bytes)' . PHP_EOL;
        };

        $promises = [];
        foreach ($groups as $group) {
            $promises[] = $client->getAsync(DD_BASE_URL . '/api.php?group=' . $group, [
                'headers' => [
                    'X-mt-rand' => mt_rand(),
                ],
            ])->then($resolver);
        }

        foreach ($promises as $promise) {
            $promise->wait();
        }

        return $users;
    }
}

header('Content-Type:text/plain');

$dt = new DistributedTracingMulti();
$users = $dt->fetchUsersFromApi(['green', 'red', 'blue']);
var_dump($users);
