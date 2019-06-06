<?php

use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tag;
use DDTrace\Type;

$spans = [];
for ($i = 0; $i < 999; $i++) {
    $span = new Span(
        'test_span',
        SpanContext::createAsRoot(),
        'test_service',
        'test_resource'
    );
    $span->setTraceAnalyticsCandidate();
    $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);
    $span->setTag(Tag::SERVICE_NAME, 'foo-service');
    $span->setTag(Tag::RESOURCE_NAME, 'foo-resource');
    $span->setTag(Tag::HTTP_URL, 'http://www.example.com');
    $span->setTag(Tag::HTTP_STATUS_CODE, 418); // I'm a teapot
    $span->setTag(Tag::ANALYTICS_KEY, 0.5);
    $spans[] = $span;
}

printf("Created %d spans\n", count($spans));
