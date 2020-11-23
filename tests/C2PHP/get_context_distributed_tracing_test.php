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

echo "OK\n";
