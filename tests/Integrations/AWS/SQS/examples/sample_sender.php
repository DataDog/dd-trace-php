<?php

use DDTrace\Tests\Integrations\AWS\SQS\TestSQSClientSupport;

require __DIR__ . '/../../../../../vendor/autoload.php';
require __DIR__ . '/../TestSQSClientSupport.php';

$client = TestSQSClientSupport::newInstance();

$options = getopt('n::');
$numberOfMessages =  isset($options['n']) ? intval($options['n']) : 1;

$defaultMessage = [
    'DelaySeconds' => 0,
    'MessageAttributes' => [
        "title" => [
            'DataType' => "String",
            'StringValue' => 'message_resource',
        ],
    ],
    'MessageBody' => "message body",
];
$batch = [];
for ($msgIndex = 0; $msgIndex < $numberOfMessages; $msgIndex++) {
    $defaultMessage['Id'] = $msgIndex;
    $batch[] = $defaultMessage;
}

$client->sendMessageBatch([
    'QueueUrl' => TestSQSClientSupport::QUEUE_URL,
    'Entries' => $batch,
]);
