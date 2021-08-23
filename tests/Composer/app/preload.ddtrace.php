<?php

use DDTrace\Tag;

error_log('requiring autoload in preload');
require_once __DIR__ . '/vendor/autoload.php';

// $tracer = GlobalTracer::get();
// error_log('Class of tracer in preload: ' . var_export(get_class($tracer), true));

// Using anything BUT GlobalTracer::get() in preload.

// $someTag = Tag::MANUAL_DROP;

file_put_contents(__DIR__ . '/touch.preload', 'DDTrace classes USED in preload');

error_log('done preload!');
