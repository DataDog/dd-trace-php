--TEST--
Legacy service aliases use the service setter's version and service-source semantics
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE=app
DD_VERSION=1.2.3
--FILE--
<?php
$root = DDTrace\start_span();
$writes = [
    'direct' => function ($span) { $span->service = 'db'; },
    'meta' => function ($span) { $span->meta['service.name'] = 'db'; },
    'bulk' => function ($span) { $span->meta = ['service.name' => 'db']; },
    'null' => function ($span) { $span->meta['service.name'] = null; },
    'unset' => function ($span) { unset($span->meta['service.name']); },
    'unchanged' => function ($span) { $span->meta['service.name'] = 'app'; },
];
foreach ($writes as $label => $write) {
    $span = DDTrace\start_span();
    $write($span);
    echo $label, ': ', json_encode([$span->service, $span->version, $span->attributes['_dd.svc_src'] ?? null]), "\n";
    DDTrace\close_span();
}
DDTrace\close_span();
?>
--EXPECT--
direct: ["db","","m"]
meta: ["db","","m"]
bulk: ["db","","m"]
null: ["","","m"]
unset: ["","","m"]
unchanged: ["app","1.2.3",null]
