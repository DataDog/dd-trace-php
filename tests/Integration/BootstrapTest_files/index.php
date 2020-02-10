<?php

use DDTrace\GlobalTracer;
use DDTrace\Tag;

$tracer = GlobalTracer::get();
$scope = $tracer->startActiveSpan('my_span');
$span = $scope->getSpan();
$span->setTag(Tag::SERVICE_NAME, 'my_service');
$span->setTag(Tag::RESOURCE_NAME, 'my_resource');
$span->setTag(Tag::SPAN_TYPE, 'custom');
$scope->close();

echo getenv('DD_TRACE_SPANS_LIMIT');
