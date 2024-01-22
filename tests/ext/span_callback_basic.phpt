--TEST--
Basic end callback behavior
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

$myGlobalClosedSpansCounter = 0;

$callback = function (\DDTrace\SpanData $span) use (&$myGlobalClosedSpansCounter) {
    $myGlobalClosedSpansCounter++;
    $activeSpan = \DDTrace\active_span();
    echo "$activeSpan->name\n";
    echo "Passed span: $span->name\n";
    //$span->meta['counter.value'] = $myGlobalClosedSpansCounter;
};

$span = \DDTrace\start_span();
$span->name = "mySpan";
//\DDTrace\set_end_callback($span, $callback);
$span->endCallback = $callback;

\DDTrace\close_span();

var_dump($myGlobalClosedSpansCounter);
//var_dump(dd_trace_serialize_closed_spans());

class mySpanWrapper {
    private $mySpan;

    private function __construct(\DDTrace\SpanData $span) {
        $this->mySpan = $span;
        /*
        // Doesn't crash
        $span->endCallback = function (\DDTrace\SpanData $span) {
            $this->end();
        };*/
    }

    public static function create(\DDTrace\SpanData $span) {
        $spanWrapper = new mySpanWrapper($span);
        // Crash
        $span->endCallback = function (\DDTrace\SpanData $span) use ($spanWrapper) {
            $spanWrapper->end();
        };
    }

    public function end() {
        echo "This is the end of {$this->mySpan->name}\n";
    }
}

$span = \DDTrace\start_span();
$span->name = "mySpan";
mySpanWrapper::create($span);
\DDTrace\close_span();

?>
--EXPECT--
