<?php

ini_set("datadog.trace.generate_root_span", 0);

$seed = getenv("SEED") ?: crc32(microtime());
print "SEED=$seed\n";
srand($seed);

set_error_handler(function ($str) {
    return !strpos($str, " expects ");
});

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
        "DDTrace\hook_method",
        "call_function",
        [],
        ["nonempty" => new stdClass()],
        new stdClass(),
        function () {
            ob_start();
            var_dump(func_get_args());
            ob_end_clean();
        }
    ];
    $garbage[] = [
        "" => $primary_garbage[array_rand($primary_garbage)],
        1 => $primary_garbage[array_rand($primary_garbage)],
    ];
    $garbage[] = [
        "foo" => $primary_garbage[array_rand($primary_garbage)],
    ];
    return $garbage;
}

function call_function(ReflectionFunction $function)
{
    $i = PHP_VERSION_ID >= 80100 ? $function->getNumberOfRequiredParameters() : 0;
    $invocations = $i == 0 ? [[]] : [];
    for (; $i < $function->getNumberOfParameters(); ++$i) {
        foreach ($invocations as $invocation) {
            foreach (generate_garbage() as $garbage) {
                $newInvocation = $invocation;
                $newInvocation[] = $garbage;
                $invocations[] = $newInvocation;
            }
        }
    }
    foreach ($invocations as $invocation) {
        try {
            $function->invokeArgs($invocation);
        } catch (ArgumentCountError $e) {
        } catch (TypeError $e) {
        }
    }
}

function runOneIteration()
{
    $ext = new ReflectionExtension("ddtrace");
    $functions = array_filter($ext->getFunctions(), function ($f) {
        return $f->name != "dd_trace_internal_fn"
            && !strpos($f->name, "Testing")
            && $f->name != "dd_trace_disable_in_request";
    });

    $return_span = [
        function () {
            return new DDTrace\SpanData();
        },
        function () {
            return DDTrace\start_span();
        },
        function () {
            return DDTrace\root_span();
        },
        function () {
            return DDTrace\active_span();
        },
    ];
    $props = array_filter(
        (new ReflectionClass('DDTrace\SpanData'))->getProperties(),
        function ($p) {
            return $p->name != "parent" && $p->name != "id";
        }
    );

    shuffle($functions);
    foreach ($functions as $function) {
        $garbages = generate_garbage();
        $ex = new Exception("");
        $exceptionClass = new ReflectionClass($ex);
        foreach ($exceptionClass->getProperties() as $prop) {
            $prop->setAccessible(true);
            try {
                $stringProp = PHP_VERSION_ID >= 80000 && $prop->getType() && $prop->getType()->getName() == "string";
                $prop->setValue($ex, !$stringProp && rand(1, 5) == 1 ? $ex : $garbages[array_rand($garbages)]);
            } catch (TypeError $e) {
            }
        }
        $garbages[] = $ex;
        foreach (array_slice($return_span, 0, rand(0, count($return_span))) as $spanreturner) {
            $span = $spanreturner();
            foreach ($props as $prop) {
                try {
                    $prop->setValue($span, $garbages[array_rand($garbages)]);
                } catch (TypeError $e) {
                }
            }
        }
        if (rand(0, 1)) {
            DDTrace\close_span();
        }
        call_function($function);
    }
}

for ($i = 0; $i < 3; ++$i) {
    runOneIteration();
}

dd_trace_disable_in_request();
runOneIteration();
