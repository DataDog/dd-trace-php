--TEST--
[profiling] allocation sampling distance is configurable
--DESCRIPTION--
This code path had a regression, so it seems worth adding a test to ensure it
cannot regress again.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    die("skip: test requires datadog-profiling");
?>
--INI--
datadog.profiling.enabled=1
datadog.profiling.allocation_enabled=1
datadog.profiling.allocation_sampling_distance=1
datadog.profiling.log_level=trace
zend.assertions=1
assert.exception=1
--FILE--
<?php
// Goal: trigger a smallish allocation so we won't run afoul of the default
// sampling distance, but also something unique-ishly sized so we can be
// reasonably sure that the log corresponds to our inputs and not accidentally
// something else.
//
// A zend_string costs 24 bytes on 64-bit architectures just for the struct,
// plus we need the string data and null.
//     24 + strlen($str) + 1
// We want a number so that the total number of bytes is evenly divisible by
// 16 to avoid worrying about rounding/padding that can apply. So let's target
// 112 total bytes which is divisible by 16 and seems relatively unique-ish:
//     112 = 24 + strlen($str) + 1
//     112 - 24 - 1 = strlen($str)
//               87 = strlen($str)
// ... except that the engine does the rounding in the wrong place for
// str_repeat, so it with our inputs it ends up doing this, with `f` being the
// function which determines the rounded amount:
//     1 * 87 + f(24 + 0 + 1)
//         87 + f(25)
//         87 + 32 = 119
// So it over-allocates by 7 bytes, and the log will have 119 bytes.
// Our regex allows for 112 (correct) and 119 (observed).
$str = \str_repeat('a', 87);
?>
Done.
--EXPECTREGEX--
.* Memory allocation profiling initialized with a sampling distance of 1 bytes.*
.* Sent stack sample with leaf frame .* of [0-9]* frames, .* labels, 11[2,9] bytes allocated, 1 allocations, and .* time interrupts to profiler.*
.*Done\..*
