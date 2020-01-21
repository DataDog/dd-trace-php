<?php

use DDTrace\Tests\Integrations\AWS\SQS\TestSQSClientSupport;

require __DIR__ . '/../../../../../vendor/autoload.php';
require __DIR__ . '/../TestSQSClientSupport.php';


function my_message_handler(array $message)
{
    error_log('Handling message: ' . print_r($message, 1));
}

$tracer = DDTrace\GlobalTracer::get();
$tracer->isolateTracedFunction('my_message_handler', function () {
    error_log('I am in callback');
    return dd_trace_forward_call();
});

$client = TestSQSClientSupport::newInstance();

$result = $client->receiveMessage(array(
    'AttributeNames' => ['SentTimestamp'],
    'MaxNumberOfMessages' => 2,
    'MessageAttributeNames' => ['All'],
    'QueueUrl' => TestSQSClientSupport::QUEUE_URL,
    'WaitTimeSeconds' => 1,
));

$messages = $result->get('Messages');

if (!empty($messages)) {
    foreach ($messages as $message) {
        error_log('Invoking the handler for: ' . print_r($message, 1));
        my_message_handler($messages);
    }
} else {
    error_log('No messages in queue');
}
