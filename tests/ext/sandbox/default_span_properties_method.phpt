--TEST--
Span properties defaults to values if not explicitly set (methods)
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=DateTime::__construct,DateTime::format
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

date_default_timezone_set('UTC');

DDTrace\trace_method('DateTime', '__construct', function (SpanData $span, array $args) {
    $span->meta = ['date' => $args[0]];
});

DDTrace\trace_method('DateTime', 'format', function (SpanData $span, array $args) {
    $span->name = 'MyDateTimeFormat';
    $span->meta = ['format' => $args[0]];
});

DDTrace\trace_method('Foo', 'main', function (SpanData $span, array $args) {
    $span->meta = ['year' => $args[0]];
});

class Foo
{
    public function main($year)
    {
        $dt = new DateTime($year . '-06-15');
        echo $dt->format('m') . PHP_EOL;
    }
}

$foo = new Foo();
$foo->main(2020);

include 'dd_dumper.inc';
dd_dump_spans();
?>
--EXPECT--
06
spans(\DDTrace\SpanData) (3) {
  Foo.main (default_span_properties_method.php, Foo.main, cli)
    year => 2020
    _dd.p.upstream_services => ZGVmYXVsdF9zcGFuX3Byb3BlcnRpZXNfbWV0aG9kLnBocA|1|1|1.000
  MyDateTimeFormat (default_span_properties_method.php, MyDateTimeFormat, cli)
    format => m
  DateTime.__construct (default_span_properties_method.php, DateTime.__construct, cli)
    date => 2020-06-15
}
