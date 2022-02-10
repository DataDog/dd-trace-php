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

function functionWithFinallyDoesThrow() {
    try {
        throw new Exception;
    } catch (NotAnException $e) {
    } catch (stdClass $e) {
    } finally {
        to_intercept(); // a no-op, to have non-empty finally
    }
}

function runFunctionWithFinallyDoesThrow() {
    try {
        functionWithFinallyDoesThrow();
    } catch (Exception $e) {}
}

function functionWithFinallyReturning() {
    try {
        throw new Exception;
    } catch (NotAnException $e) {
    } finally {
        return 1;
    }
}

function createGenerator() {
    $g = generator();
    return $g;
}

function createGeneratorUnused() {
    generator();
}

function throwingGenerator() {
    throw new \Exception("EXCEPTIONAL");
    yield;
}

function runThrowingGenerator() {
    try {
       throwingGenerator()->valid();
    } catch (Exception $e) {}
}

function generatorWithFinally() {
    try {
        yield;
    } finally {
        to_intercept(); // a no-op, to have non-empty finally
    }
}

function runGeneratorWithFinally() {
    $g = generatorWithFinally();
    $g->valid();
    return $g;
}

function generatorWithFinallyReturn() {
    try {
        yield;
    } finally {
        return "RETVAL";
    }
}

function runGeneratorWithFinallyReturn() {
    $g = generatorWithFinallyReturn();
    $g->valid();
    return $g;
}

function bailout() {
    class Foo implements ArrayAccess {
    }
}
