<?php

// The parent records the CDN requests of this process until it sends finish.
echo "fixture_child_ready\n";
if (trim((string) fgets(STDIN)) !== 'finish') throw new RuntimeException('finish command missing');
// The serializer gives the service that APM shows, after DD_SERVICE_MAPPING.
\DDTrace\start_span();
\DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
echo 'span_service=', $spans[0]['service'], "\n";
