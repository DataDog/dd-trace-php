<?php

function to_intercept() {
}

function returns() {
    return "RETVAL";
}

function generator() {
    yield;
}

function callInternalTimeFunction() {
    time();
}

function callThrowingInternalFunction() {
    try {
        (new SplPriorityQueue)->extract();
    } catch (\Exception $e) {}
}

function throws() {
    throw new \Exception("EXCEPTIONAL");
}

function wrap_throws() {
    try {
        throws();
    } catch (Exception $e) {}
}

function functionDoesNotThrow() {
    try {
        to_intercept(); // no-op
    } catch (Exception $e) {}

    try {
        try {
            try {
                throw new Exception;
            } catch (stdClass $e) {}
        } catch (NotAnException $e) {
        } catch (Exception $e) {
        }
    } catch (NotAnException $e) {}

    try {
        to_intercept(); // no-op
    } catch (Exception $e) {}

    return 1;
}

function functionDoesThrow() {
    try {
        throw new Exception;
    } catch (NotAnException $e) {
    } catch (stdClass $e) {}
}

function runFunctionDoesThrow() {
    try {
        functionDoesThrow();
    } catch (Exception $e) {}
}

function bailout() {
    class Foo implements ArrayAccess {
    }
}

if (PHP_VERSION_ID >= 50500) {
    require __DIR__ . "/finally_stubs.php";
}