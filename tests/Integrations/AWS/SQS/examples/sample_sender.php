<?php

use DDTrace\Tests\Integrations\AWS\SQS\TestSQSClientSupport;

require __DIR__ . '/../../../../../vendor/autoload.php';
require __DIR__ . '/../TestSQSClientSupport.php';

$client = TestSQSClientSupport::newInstance();

$client->sendMessage([
    'DelaySeconds' => 0,
    'MessageAttributes' => [
        "title" => [
            'DataType' => "String",
            'StringValue' => 'message_resource',
        ],
    ],
    'MessageBody' => "message body",
    'QueueUrl' => TestSQSClientSupport::QUEUE_URL,
]);
