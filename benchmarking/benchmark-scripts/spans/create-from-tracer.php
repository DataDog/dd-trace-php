<?php

use DDTrace\GlobalTracer;
use DDTrace\Tag;
use DDTrace\Type;

$tracer = GlobalTracer::get();
$i = 0;
for (; $i < 9999; $i++) {
    $scope = $tracer->startActiveSpan('foo-operation-' . $i);
    $span = $scope->getSpan();
    $span->setTraceAnalyticsCandidate();
    $span->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
    $span->setTag(Tag::SERVICE_NAME, 'foo-service');
    $span->setTag(Tag::RESOURCE_NAME, 'foo-resource');
    $span->setTag(Tag::ANALYTICS_KEY, lcg_value());
    $scope->close();
}

printf("Created %d spans with 'Tracer::startActiveSpan()'\n", $i + 1);
