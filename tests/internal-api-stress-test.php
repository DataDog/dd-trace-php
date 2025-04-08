<?php

ini_set("datadog.trace.generate_root_span", 0);

$seed = getenv("SEED") ?: crc32(microtime());
print "SEED=$seed\n";
srand($seed);

set_error_handler(function ($str) {
    return !strpos($str, " expects ");
});

$return_span = [
    function () {
        return new DDTrace\SpanData();
    },
    function () {
        return DDTrace\start_span();
    },
    function () {
        return DDTrace\start_trace_span();
    },
    function () {
        return DDTrace\root_span();
    },
    function () {
        return DDTrace\active_span();
    },
];

// Otherwise we grow var_dump() graphs exponentially...
function ensure_bounded_nesting_depth()
{
    $depth = 0;
    $stack = DDTrace\active_stack();
    while (!$stack->active && $stack->parent) {
        ++$depth;
        $stack = $stack->parent;
    }

    $span = DDTrace\active_span();
    while ($span) {
        ++$depth;
        if ($span->parent && $span->parent->stack == $span->stack) {
            $span = $span->parent;
        } else {
            $stack = $span->stack;
            do {
                ++$depth;
                $stack = $stack->parent;
            } while ($stack && !$stack->active);
            $span = $stack ? $stack->active : null;
        }
    }

    if ($depth >= 5) {
        ini_set("datadog.trace.enabled", "0");
        ini_set("datadog.trace.enabled", "1");
        DDTrace\switch_stack(new DDTrace\SpanStack);
    }
}

function garbage_stack()
{
    global $return_span;
    if (rand(0, 1) == 0) {
        DDTrace\switch_stack();
    }
    if (rand(0, 5) == 0) {
        return DDTrace\create_stack();
    }
    $span = $return_span[rand(0, count($return_span) - 1)]();
    if ($span) {
        return $span->stack;
    }
    $span = DDTrace\root_span();
    if ($span) {
        return $span->stack;
    }
    return DDTrace\create_stack();
}

function generate_garbage()
{
    $garbage = $primary_garbage = [
        null,
        0,
        1,
        NAN,
        PHP_INT_MIN,
        PHP_INT_MAX,
        "",
        'DDTrace\hook_method',
        "call_function",
        [],
        ["nonempty" => new stdClass()],
        new stdClass(),
        function ($hook = null) {
            $args = func_get_args();
            if (is_array($args[2] ?? null)) {
                if (rand(1, 10) != 1) {
                    return; // Otel hooks cannot be removed
                }
            }
            if (rand(1, 3) == 1) {
                ob_start();
                var_dump($args);
                ob_end_clean();
            }
            if ($hook instanceof DDTrace\HookData) {
                if (rand(1, 2) != 1) {
                    DDTrace\remove_hook($hook->id);
                }
            } elseif (rand(1, 5) == 1) {
                dd_untrace('DDTrace\hook_method');
                dd_untrace('call_function');
            }
        },
        garbage_stack(),
    ];
    $garbage[] = [
        "" => $primary_garbage[array_rand($primary_garbage)],
        1 => $primary_garbage[array_rand($primary_garbage)],
    ];
    $garbage[] = [
        "foo" => $primary_garbage[array_rand($primary_garbage)],
    ];

    ensure_bounded_nesting_depth();

    return $garbage;
}

$minFunctionArgs = [];

function call_function(ReflectionFunction $function)
{
    global $minFunctionArgs;
    print date('Y-m-d H:i:s') . " Executing: {$function->name}\n";

    $i = PHP_VERSION_ID >= 80100 ? $function->getNumberOfRequiredParameters() : ($minFunctionArgs[$function->name] ?? 0);
    $invocations = $i == 0 ? [[]] : [];
    $invocationTypeMap = [];
    $invNum = 0;
    $existingGarbage = [generate_garbage()];
    for (; $i < $function->getNumberOfParameters(); ++$i) {
        foreach ($invocations as $invocation) {
            if (rand(1, max(1, ceil(2 ** $i))) == 1) {
                $useGarbage = $existingGarbage[] = generate_garbage();
            } else {
                $useGarbage = $existingGarbage[array_rand($existingGarbage)];
            }
            foreach ($useGarbage as $garbage) {
                $newInvocation = $invocation;
                $newInvocation[] = $garbage;
                $invocations[++$invNum] = $newInvocation;
                foreach ($newInvocation as $arg => $val) {
                    $invocationTypeMap[$arg][gettype($val)][] = $invNum;
                    if (is_object($val)) {
                        $c = get_class($val);
                        $invocationTypeMap[$arg][strrchr($c, '\\') ?: $c][] = $invNum;
                    }
                }
            }
        }
    }

    foreach ($invocations as &$invocation) {
        try {
            $function->invokeArgs($invocation);
        } catch (ArgumentCountError $e) {
            $minFunctionArgs[$function->name] = $argc = count($invocation);
            foreach ($invocations as $k => $cur) {
                if (count($cur) <= $argc) {
                    unset($invocations[$k]);
                }
            }
        } catch (TypeError $e) {
            # Fatal error: Uncaught TypeError: DDTrace\hook_method(): Argument #1 ($className) must be of type string, array given
            if (preg_match('(Argument #(\d+).*?(?|got ([a-z]+)|([a-z]+) given))i', $e->getMessage(), $m)) {
                $arg = $m[1];
                $argNum = $arg - 1;
                $type = $m[2];
                $found = $invocationTypeMap[$arg - 1][$type] ?? $invocationTypeMap[$arg - 1]['\\' . $type] ?? [];
                foreach ($found as $k) {
                    unset($invocations[$k]);
                }
            }
        }
    }
}

function runOneIteration()
{
    global $return_span;

    $ext = new ReflectionExtension("ddtrace");
    $functions = array_filter($ext->getFunctions(), function ($f) {
        return $f->name != "dd_trace_internal_fn"
            && !strpos($f->name, "Testing")
            && $f->name != "dd_trace_disable_in_request"
            && (PHP_VERSION_ID >= 70100 || $f->name != 'DDTrace\curl_multi_exec_get_request_spans')
            && $f->name != "DDTrace\Internal\handle_fork";
    });

    $props = array_filter(
        (new ReflectionClass('DDTrace\SpanData'))->getProperties(),
        function ($p) {
            return $p->name != "parent" && $p->name != "id" && $p->name != "stack";
        }
    );

    shuffle($functions);
    foreach ($functions as $function) {
        ini_set("datadog.autofinish_spans", rand(0, 1));

        $garbages = generate_garbage();
        $ex = new Exception("");
        $exceptionClass = new ReflectionClass($ex);
        foreach ($exceptionClass->getProperties() as $prop) {
            $prop->setAccessible(true);
            try {
                $selfAssign = $prop->getName() == "previous" && rand(1, 5) == 1;
                $prop->setValue($ex, $selfAssign ? $ex : $garbages[array_rand($garbages)]);
            } catch (TypeError $e) {
            }
        }
        $garbages[] = $ex;
        foreach (array_slice($return_span, 0, rand(0, count($return_span))) as $spanreturner) {
            if (rand(0, 8) == 1) {
                DDTrace\create_stack();
            } else {
                $span = $spanreturner();
                foreach ($props as $prop) {
                    try {
                        $prop->setValue($span, $garbages[array_rand($garbages)]);
                    } catch (Error $e) {
                    }
                }
            }
        }
        if (rand(0, 1)) {
            DDTrace\close_span();
        }
        if (rand(0, 2) == 0) {
            DDTrace\switch_stack();
        }
        call_function($function);
    }
}

for ($i = 0; $i < 3; ++$i) {
    runOneIteration();
}

dd_trace_disable_in_request();
runOneIteration();
