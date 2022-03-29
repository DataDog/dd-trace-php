<?php

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
        return;
    }
}

if (PHP_VERSION_ID >= 70000) eval(<<<DECL
function generatorWithFinallyReturnValue() {
    try {
        yield;
    } finally {
        return "RETVAL";
    }
}
DECL
);

function runGeneratorWithFinallyReturn() {
    $g = generatorWithFinallyReturn();
    $g->valid();
    return $g;
}

function runGeneratorWithFinallyReturnValue() {
    $g = generatorWithFinallyReturnValue();
    $g->valid();
    return $g;
}

function yieldingGenerator() {
    yield;
    yield 1;
    yield 10 => 2;
}

function runYieldingGenerator() {
    foreach (yieldingGenerator() as $k => $v);
}

function receivingGenerator() {
    try {
        time();
        $ex = yield;
    } catch (Exception $e) {}
    yield;
    $val = yield;
}

function runReceivingGenerator() {
    $g = receivingGenerator();
    $g->throw(new Exception);
    $g->next();
    $g->send(123);
    unset($g);
}
