--TEST--
Distributed tracing context with an origin
--ENV--
HTTP_X_DATADOG_ORIGIN=some-origin
HTTP_X_DATADOG_PRIORITY_SAMPLING=1
--FILE--
<?php

use DDTrace\GlobalTracer;

$tracer = GlobalTracer::get();
$scope = $tracer->getRootScope();
$span = $scope->getSpan();
$context = $span->getContext();
$context->origin = 'some-origin';
$context->propagatedPrioritySampling = 1;
$context->parentId = '789';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://httpbin_integration/headers',
    CURLOPT_RETURNTRANSFER => true,
]);
$response = json_decode(curl_exec($ch), 1);
if (empty($response['headers']['X-Datadog-Origin']) || $response['headers']['X-Datadog-Origin'] !== 'some-origin') {
    throw new Exception('Unexpected origin header. ' . var_export($response, true));
}

curl_close($ch);

var_dump($context->origin);
echo "OK\n";
?>
--EXPECT--
string(11) "some-origin"
OK
