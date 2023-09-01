<?php

include_once __DIR__ . '/fake_span.inc';

use DDTrace\Span;

function intermediarySetTag() {
    $span = new Span();
    $span->setTag('key', 'value');
}
