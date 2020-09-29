<?php

use DDTrace\GlobalTracer;
use DDTrace\Tag;

function do_manual_instrumentation()
{
    $tracer = GlobalTracer::get();
    $rootSpan = $tracer->startActiveSpan("root-operation")->getSpan();
    $rootSpan->setTag(Tag::SERVICE_NAME, "long-running-service");
    $rootSpan->setTag(Tag::RESOURCE_NAME, "root-resource");

    $subSpan = $tracer->startActiveSpan('sub-operation')->getSpan();
    $rootSpan->setTag(Tag::RESOURCE_NAME, "sub-resource");
    $subSpan->finish();
    $rootSpan->finish();

    if (PHP_MAJOR_VERSION === 5) {
        // Auto flushing is not yet supported on PHP 5.
        $tracer->flush();
    }
}

// Sending multiple traces
do_manual_instrumentation();
do_manual_instrumentation();
do_manual_instrumentation();

error_log('Script is done');
