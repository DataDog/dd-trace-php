--TEST--
Exception replay does not capture uninitialized locals of a frame unwinding from a skipped named argument's default
--DESCRIPTION--
The default of the skipped parameter $a constructs a Thrower whose constructor
throws. The callee frame still has uninitialized CVs at that point, as
zend_handle_undef_args() runs before i_init_func_execute_data(). Once the
exception is rethrown into the callee frame, its opline points to
EG(exception_op), which makes it indistinguishable from a running frame.
Releasing the failed Thrower object then triggers a GC run. When running inside
a fiber, GC destructors run in a separate fiber linked to the callee frame
(PHP >= 8.4), without restoring EG(opline_before_exception) into the frame,
and create an exception there.
The GC root buffer is filled up to just below the threshold with varying
offsets, to trigger the GC run once during the constructor and once after the
rethrow. The first mode creates the thrown exception while the callee frame
is on the stack, the second one throws an exception created beforehand.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip: new in initializers requires PHP 8.1'); ?>
--ENV--
DD_EXCEPTION_REPLAY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
--INI--
zend.exception_ignore_args=1
--FILE--
<?php

class D {
    public $self;
    public static $inner;

    public function __destruct() {
        self::$inner = new Exception("inner");
    }
}

class Thrower {
    public static $self;
    public static $precreated;
    public static $offset;

    public function __construct() {
        self::$self = $this; // releasing the failed object after the rethrow then adds a GC root
        $d = new D; $d->self = $d; unset($d);
        $status = gc_status();
        $n = $status['threshold'] - $status['roots'] - self::$offset;
        for ($i = 0; $i < $n; $i++) { $x = new stdClass; $x->self = $x; unset($x); }
        throw self::$precreated ?? new Exception("outer");
    }
}

function pollute() {
    $p0 = 100; $p1 = 101; $p2 = 102; $p3 = 103; $p4 = 104;
    return 0;
}

function callee($a = new Thrower, $b = 2) {
    $l1 = 1; $l2 = 2; $l3 = 3;
    return $b;
}

function run($offset) {
    D::$inner = null;
    Thrower::$offset = $offset;
    // Outside of fibers, GC calls destructors directly, which restores the opline before the exception
    (new Fiber(static function () {
        pollute();
        try {
            callee(b: 5);
        } catch (Exception $e) {
        }
    }))->start();
    if (!D::$inner) {
        return null;
    }

    $trace = new ReflectionProperty(Exception::class, 'trace');
    @$trace->setAccessible(true);
    $frames = $trace->getValue(D::$inner);
    $in_constructor = false;
    foreach ($frames as $i => $frame) {
        if (($frame["class"] ?? null) === "Thrower") {
            $in_constructor = true;
        }
        // A frame's locals are the ones of the frame it was called from
        if (($frame["function"] ?? null) === "callee" && $i > 0) {
            return [$in_constructor ? "constructor" : "after rethrow", $frames[$i - 1]["locals"] ?? null];
        }
    }
    return null; // GC did not run while the callee frame was on the stack
}

foreach (["created", "precreated"] as $mode) {
    Thrower::$precreated = $mode == "precreated" ? new Exception("precreated") : null;
    $reached = [];
    for ($offset = 0; $offset < 12; ++$offset) {
        if ($result = run($offset)) {
            [$when, $locals] = $result;
            if ($locals !== ["b" => 5]) {
                echo "$mode, offset $offset, $when: unexpected callee locals: ", json_encode($locals), "\n";
            }
            $reached[$when] = true;
        }
    }
    ksort($reached);
    echo "$mode: GC reached the callee frame during: ", implode(", ", array_keys($reached)), "\n";
}

?>
--EXPECT--
created: GC reached the callee frame during: after rethrow, constructor
precreated: GC reached the callee frame during: after rethrow, constructor
