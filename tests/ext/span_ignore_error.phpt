--TEST--
SpanData::$ignoreError and the deprecated meta['error.ignored'] suppress the span error; the child gets track_error=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
var_dump((new DDTrace\SpanData)->ignoreError);

function run($label, $ignore) {
    echo "$label\n";
    $e = new Exception("boom");
    $root = \DDTrace\start_span();
    $root->name = 'root';
    $child = \DDTrace\start_span();
    $child->name = 'child';
    $child->exception = $e;
    \DDTrace\close_span();
    $root->exception = $e;
    $ignore($root);
    \DDTrace\close_span();
    foreach (dd_trace_serialize_closed_spans() as $span) {
        $attrs = isset($span['attributes']) ? $span['attributes'] : [];
        $errorTags = array_filter(array_keys($attrs), function ($k) { return strpos($k, 'error') === 0; });
        sort($errorTags);
        echo "  ", $span['name'], ": error=", isset($span['error']) ? $span['error'] : 0,
            " error tags=[", implode(',', $errorTags), "]",
            " track_error=", isset($attrs['track_error']) ? $attrs['track_error'] : '-', "\n";
    }
    echo "  root ignoreError after flush: ", var_export($root->ignoreError, true), "\n";
}

run('not ignored', function ($root) {});
run('property', function ($root) { $root->ignoreError = true; });
run('deprecated tag', function ($root) { $root->meta['error.ignored'] = 1; });
run('deprecated falsy tag cannot unset the property', function ($root) { $root->ignoreError = true; $root->meta['error.ignored'] = 0; });
run('deprecated falsy tag alone', function ($root) { $root->meta['error.ignored'] = 0; });
run('falsy attributes tag does not mask a truthy meta tag', function ($root) { $root->attributes['error.ignored'] = 0; $root->meta['error.ignored'] = 1; });
?>
--EXPECT--
bool(false)
not ignored
  root: error=1 error tags=[error.message,error.stack,error.type] track_error=-
  child: error=1 error tags=[error.message,error.stack,error.type] track_error=-
  root ignoreError after flush: false
property
  root: error=0 error tags=[] track_error=-
  child: error=1 error tags=[error.message,error.stack,error.type] track_error=false
  root ignoreError after flush: true
deprecated tag
  root: error=0 error tags=[] track_error=-
  child: error=1 error tags=[error.message,error.stack,error.type] track_error=false
  root ignoreError after flush: true
deprecated falsy tag cannot unset the property
  root: error=0 error tags=[] track_error=-
  child: error=1 error tags=[error.message,error.stack,error.type] track_error=false
  root ignoreError after flush: true
deprecated falsy tag alone
  root: error=1 error tags=[error.message,error.stack,error.type] track_error=-
  child: error=1 error tags=[error.message,error.stack,error.type] track_error=-
  root ignoreError after flush: false
falsy attributes tag does not mask a truthy meta tag
  root: error=0 error tags=[] track_error=-
  child: error=1 error tags=[error.message,error.stack,error.type] track_error=false
  root ignoreError after flush: true
