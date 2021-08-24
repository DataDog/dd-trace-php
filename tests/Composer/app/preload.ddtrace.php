<?php

use DDTrace\Tag;
use DDTrace\GlobalTracer;

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/custom_autoloaders.php';
(new AutoloaderThatFails())->register();

function lazy_loading()
{
    // Even if not executed during preload, some functions might use GlobalTracer, e.g. when defining services
    // for container injection that are lazily evaluated during the request.
    $tracer = GlobalTracer::get();
}


// Using anything BUT GlobalTracer::get() in preload.
$someTag = Tag::MANUAL_DROP;

file_put_contents(__DIR__ . '/touch.preload', 'DDTrace classes USED in preload');
