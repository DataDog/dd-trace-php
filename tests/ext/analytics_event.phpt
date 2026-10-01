--TEST--
analytics.event maps to the _dd1.sr.eausr metric, set via $meta or $attributes
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$cases = [
    'meta string true' => function ($s) { $s->meta['analytics.event'] = 'true'; },
    'meta string F' => function ($s) { $s->meta['analytics.event'] = 'F'; },
    'meta bool true' => function ($s) { $s->meta['analytics.event'] = true; },
    'attributes bool false' => function ($s) { $s->attributes['analytics.event'] = false; },
    'attributes float' => function ($s) { $s->attributes['analytics.event'] = 0.5; },
    'attributes string 1' => function ($s) { $s->attributes['analytics.event'] = '1'; },
    'invalid string' => function ($s) { $s->meta['analytics.event'] = 'yes'; },
    'invalid string keeps metric' => function ($s) { $s->meta['analytics.event'] = 'yes'; $s->metrics['_dd1.sr.eausr'] = 0.25; },
    'valid value wins over metric' => function ($s) { $s->meta['analytics.event'] = 'true'; $s->metrics['_dd1.sr.eausr'] = 0; },
    'metric only' => function ($s) { $s->metrics['_dd1.sr.eausr'] = 0.75; },
];
foreach ($cases as $name => $set) {
    $s = \DDTrace\start_span();
    $s->name = $name;
    $set($s);
    \DDTrace\close_span();
}
foreach (dd_trace_serialize_closed_spans() as $span) {
    $attrs = $span['attributes'];
    echo $span['name'], ': eausr=', var_export(isset($attrs['_dd1.sr.eausr']) ? $attrs['_dd1.sr.eausr'] : null, true),
        ' analytics.event=', isset($attrs['analytics.event']) ? 'kept' : 'removed', "\n";
}
?>
--EXPECT--
metric only: eausr=0.75 analytics.event=removed
valid value wins over metric: eausr=1.0 analytics.event=removed
invalid string keeps metric: eausr=0.25 analytics.event=removed
invalid string: eausr=NULL analytics.event=removed
attributes string 1: eausr=1.0 analytics.event=removed
attributes float: eausr=0.5 analytics.event=removed
attributes bool false: eausr=0.0 analytics.event=removed
meta bool true: eausr=1.0 analytics.event=removed
meta string F: eausr=0.0 analytics.event=removed
meta string true: eausr=1.0 analytics.event=removed
