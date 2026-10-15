<?php

namespace DDTrace\Util;

use DDTrace\SpanKind;
use DDTrace\Tag;

class SpanKindNames
{
    /** The span.kind tag value of each canonical DDTrace\SpanKind. */
    const NAMES = [
        SpanKind::INTERNAL => Tag::SPAN_KIND_VALUE_INTERNAL,
        SpanKind::SERVER => Tag::SPAN_KIND_VALUE_SERVER,
        SpanKind::CLIENT => Tag::SPAN_KIND_VALUE_CLIENT,
        SpanKind::PRODUCER => Tag::SPAN_KIND_VALUE_PRODUCER,
        SpanKind::CONSUMER => Tag::SPAN_KIND_VALUE_CONSUMER,
    ];
}
