--TEST--
Line ranges disambiguate enclosing functions without excluding exact or sole matches
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function single_line_extended() {
    $values = array_map(function ($v) { return $v + 1; }, [1, 2, 3]);
    return $values;
}

function single_line_exact() {
    $values = array_map(function ($v) { return $v + 1; }, [1, 2, 3]);
    return $values;
}

function closure_extended() {
    $values = array_map(function ($v) { ++$v; return
        $v + 1;
    }, [1, 2]);
    return $values;
}

function closure_exact() {
    $values = array_map(function ($v) { ++$v; return
        $v + 1;
    }, [1, 2]);
    return $values;
}

function closure_inside() {
    $values = array_map(function ($v) { ++$v; return
        $v + 1;
    }, [1, 2]);
    return $values;
}

function closure_shared_line() {
    $values = array_map(function ($v) { return
        $v + 1; }, [1, 2]);
    return $values;
}

function sole_case() { return 42; }

function check_range($label, $function, $from, $till) {
    $events = [];
    $spans = [];
    $id = DDTrace\install_line_hook(__FILE__, $from,
        function ($h) use (&$events, &$spans) {
            $events[] = 'B';
            $spans[] = $h->span();
        }, $till,
        function () use (&$events) { $events[] = 'E'; });
    $function();
    DDTrace\remove_hook($id);
    echo $label, ': ', implode('', $events), "\n";
    foreach ($spans as $span) {
        if ($span->getDuration() <= 0) echo "FAIL: unclosed span\n";
    }
}

$root = DDTrace\start_span();
$line = (new ReflectionFunction('single_line_extended'))->getStartLine() + 1;
check_range('single-line closure extends past inner', 'single_line_extended', $line, $line + 1);
$line = (new ReflectionFunction('single_line_exact'))->getStartLine() + 1;
check_range('single-line closure exact inner', 'single_line_exact', $line, $line);

$closure = (new ReflectionFunction('closure_extended'))->getStartLine() + 1;
check_range('closure extends past inner', 'closure_extended', $closure, $closure + 3);
$closure = (new ReflectionFunction('closure_exact'))->getStartLine() + 1;
check_range('closure exact inner', 'closure_exact', $closure, $closure + 2);
$closure = (new ReflectionFunction('closure_inside'))->getStartLine() + 1;
check_range('starts inside inner and extends past it', 'closure_inside', $closure + 1, $closure + 3);
$line = (new ReflectionFunction('closure_shared_line'))->getStartLine() + 2;
check_range('starts inside inner with two targets', 'closure_shared_line', $line, $line + 1);

$sole = (new ReflectionFunction('sole_case'))->getStartLine();
check_range('sole target extends past function', 'sole_case', $sole, $sole + 1);
DDTrace\close_span();
?>
--EXPECT--
single-line closure extends past inner: BE
single-line closure exact inner: BBEBEBEE
closure extends past inner: BE
closure exact inner: BBEBEE
starts inside inner and extends past it: BEBE
starts inside inner with two targets: BBEBEE
sole target extends past function: BE
