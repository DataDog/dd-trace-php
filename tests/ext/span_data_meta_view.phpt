--TEST--
SpanData::$meta and $metrics are views onto SpanData::$attributes
--DESCRIPTION--
$attributes is the single tag store: writes, unsets and reads through the deprecated $meta
(non-numeric entries, stored as strings) and $metrics (numeric entries, stored as floats) act on it,
and the last write wins.
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TAGS=team:a,region:eu
--FILE--
<?php
$root = \DDTrace\start_span();
$span = \DDTrace\start_span();

echo "-- tracer tag read and overridden through meta\n";
$span->attributes['http.url'] = 'https://tracer.example';
var_dump($span->meta['http.url']);
$span->meta['http.url'] = 'https://user.example';
var_dump($span->attributes['http.url']);

echo "-- unset through meta\n";
unset($span->meta['http.url']);
var_dump(isset($span->attributes['http.url']), isset($span->meta['http.url']));

echo "-- buckets and coercion\n";
$span->meta['num'] = 42;
$span->metrics['m'] = 3;
var_dump($span->attributes['num'], $span->attributes['m']);
var_dump(isset($span->meta['m']), isset($span->metrics['m']), isset($span->metrics['num']));
var_dump($span->meta['missing'] ?? 'default', empty($span->meta['num']));

echo "-- array functions on a read\n";
var_dump(is_array($span->meta), array_key_exists('num', $span->meta), array_key_exists('m', $span->meta));
$merged = array_merge($span->meta, ['x' => 'y']);
var_dump($merged['num'], $merged['x']);
$span->meta += ['added' => 'yes'];
var_dump($span->attributes['added']);

echo "-- nested values keep the old string leaves\n";
$span->meta['nested'] = ['a' => 1, 'b' => [true, null], 'c' => []];
var_dump($span->attributes['nested']);

echo "-- whole-array assignment replaces the bucket\n";
$span->meta = ['only' => 'this'];
var_dump($span->meta, $span->metrics['m'], isset($span->attributes['team']));

echo "-- iteration and the view object\n";
$span->meta['k2'] = 'v2';
foreach ($span->meta as $k => $v) {
    echo "$k=$v\n";
}
$view = &$span->meta;
var_dump(get_class($view), count($view), $view instanceof ArrayAccess, $view instanceof Countable, $view instanceof IteratorAggregate);
$view['via_ref'] = 'r';
unset($view);
var_dump($span->attributes['via_ref']);

echo "-- isset/?? contexts and writes through attributes\n";
$x = $span->meta ?? [];
var_dump(is_array($x), array_merge($x, ['extra' => 'e'])['extra']);
$span->attributes['via_attrs'] = 'a';
var_dump(isset($span->meta['via_attrs']), isset($span->metrics['via_attrs']));
\DDTrace\close_span();

echo "-- DD_TAGS globals\n";
$s1 = \DDTrace\start_span();
$s1->name = 's1';
unset($s1->meta['team']);
\DDTrace\close_span();
$s2 = \DDTrace\start_span();
$s2->name = 's2';
$s2->meta['team'] = 'b';
\DDTrace\close_span();
\DDTrace\close_span();
foreach (dd_trace_serialize_closed_spans() as $serialized) {
    if (in_array($serialized['name'], ['s1', 's2'])) {
        echo $serialized['name'], ': team=', $serialized['attributes']['team'] ?? 'unset', ' region=', $serialized['attributes']['region'], "\n";
    }
}
?>
--EXPECT--
-- tracer tag read and overridden through meta
string(22) "https://tracer.example"
string(20) "https://user.example"
-- unset through meta
bool(false)
bool(false)
-- buckets and coercion
string(2) "42"
float(3)
bool(false)
bool(true)
bool(false)
string(7) "default"
bool(false)
-- array functions on a read
bool(true)
bool(true)
bool(false)
string(2) "42"
string(1) "y"
string(3) "yes"
-- nested values keep the old string leaves
array(3) {
  ["a"]=>
  string(1) "1"
  ["b"]=>
  array(2) {
    [0]=>
    string(4) "true"
    [1]=>
    string(4) "null"
  }
  ["c"]=>
  string(0) ""
}
-- whole-array assignment replaces the bucket
array(1) {
  ["only"]=>
  string(4) "this"
}
float(3)
bool(false)
-- iteration and the view object
only=this
k2=v2
string(20) "DDTrace\SpanTagsView"
int(2)
bool(true)
bool(true)
bool(true)
string(1) "r"
-- isset/?? contexts and writes through attributes
bool(true)
string(1) "e"
bool(true)
bool(false)
-- DD_TAGS globals
s2: team=b region=eu
s1: team=unset region=eu
