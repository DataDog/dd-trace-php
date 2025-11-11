<?php

namespace zai\exceptions\test;

if (PHP_VERSION_ID > 70000) {
    class ChildError extends \Error {}

    function legitimate_error() {
        return new \Error("msg");
    }

    function child_error() {
        return new ChildError("msg");
    }
}

class ChildException extends \Exception {}

function legitimate_exception() {
    return new \Exception("msg");
}

function child_exception() {
    return new ChildException("msg");
}

function broken_exception() {
    $e = new \Exception;
    $ref = new \ReflectionClass($e);
    if (PHP_VERSION_ID < 80000) {
        // trace is a typed property in PHP 8 and will always be array
        $p = $ref->getProperty("trace");
        $p->setAccessible(true);
        $p->setValue($e, "Not an array");
    }
    $p = $ref->getProperty("message");
    if (PHP_VERSION_ID < 80500) {
        $p->setAccessible(true);
    }
    $p->setValue($e, new \stdClass);
    return $e;
}

function trace_with_bad_frame() {
    return [
        ["file" => "functions.php", "line" => 7],
        "foo",
    ];
}

function good_trace_with_all_values() {
    return [
        ["file" => "functions.php", "line" => 7, "class" => "Foo", "type" => "--", "function" => "bar"],
    ];
}

function trace_with_invalid_filename() {
    return [
        ["file" => 123]
    ];
}

function trace_without_line_number() {
    return [
        ["file" => "functions.php"]
    ];
}

function trace_with_invalid_line_number() {
    return [
        ["file" => "functions.php", "line" => new \stdClass]
    ];
}

function trace_with_invalid_class_type_function() {
    return [
        ["file" => "functions.php", "line" => 7, "class" => new \stdClass, "type" => [], "function" => null]
    ];
}
