<?php

use DDTrace\Tests\Integrations\AWS\SQS\TestSQSClientSupport;

require __DIR__ . '/../../../../../vendor/autoload.php';
require __DIR__ . '/../TestSQSClientSupport.php';

use DDTrace\Tag;

function my_message_handler(array $message)
{
    // A integration using the legacy api
    $ch = curl_init('http://httpbin_integration/status/200');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    // An integration using the sandboxed API
    $mysqli = \mysqli_connect('mysql_integration', 'test', 'test', 'test');
    $mysqli->close();
}

$tracer = DDTrace\GlobalTracer::get();
\dd_trace('my_message_handler', function () use ($tracer) {
    list($message) = func_get_args();
    $tracer->reset();
    error_log('reset');
    $scope = $tracer->startRootSpan('my_operation');
    try {
        $result = dd_trace_forward_call();
        $span = $scope->getSpan();
        $span->setTag(Tag::SERVICE_NAME, 'my_service');
        $span->setTag(Tag::RESOURCE_NAME, $message['MessageAttributes']['title']['StringValue']);
        $span->setTag(Tag::SPAN_TYPE, 'custom');
        $span->finish();
        $scope->close();
        return $result;
    } catch (\Exception $ex) {
        $span->setError($ex);
        throw $ex;
    } finally {
        $span->finish();
        $scope->close();
        $tracer->flush();
    }
    error_log('done');
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
        // error_log('Invoking the handler for: ' . print_r($message, 1));
        my_message_handler($message);
    }
// } else {
//     error_log('No messages in queue');
}
