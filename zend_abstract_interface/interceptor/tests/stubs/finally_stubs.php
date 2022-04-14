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

function yieldFromArrayGenerator() {
    yield 0;
    yield from [1, 2, 3];
    yield 4;
}

function runYieldFromArrayGenerator() {
    foreach (yieldFromArrayGenerator() as $k => $v);
}

function yieldFromArrayGeneratorThrows() {
    try {
        yield from [0, -1];
    } catch (Exception $e) {
        yield 1;
    }
}

function runYieldFromArrayGeneratorThrows() {
    $g = yieldFromArrayGeneratorThrows();
    $g->throw(new Exception);
    $g->next();
}

function yieldFromIteratorGenerator() {
    yield from new ArrayIterator([0, 1]);
}

function runYieldFromIteratorGenerator() {
    foreach (yieldFromIteratorGenerator() as $k => $v);
}

function yieldFromIteratorGeneratorThrows() {
    try {
        yield from new class(new ArrayIterator([-1, -2])) extends IteratorIterator {
            public function key() {
                throw new Exception;
            }
        };
    } catch (Exception $e) {
        yield 0;
    }
    try {
        yield from new class(new ArrayIterator([1, -2])) extends IteratorIterator {
            public function key() {
                if ($k = parent::key()) {
                    throw new Exception;
                }
                return $k;
            }
        };
    } catch (Exception $e) {
        yield 2;
    }
}

function runYieldFromIteratorGeneratorThrows() {
    foreach (yieldFromIteratorGeneratorThrows() as $k => $v);
}

function yieldFromInnerGenerator() {
    yield 1;
    yield 2;
}

function yieldFromGenerator() {
    yield 0;
    yield from yieldFromInnerGenerator();
}

function runYieldFromGenerator() {
    foreach (yieldFromGenerator() as $k => $v);
}

function yieldFromMultiGenerator($gen) {
    yield 0;
    yield from $gen;
}

function runYieldFromMultiGenerator() {
    $gen = yieldFromInnerGenerator();
    $g1 = yieldFromMultiGenerator($gen);
    $g2 = yieldFromMultiGenerator($gen);
    $g1->current();
    $g2->current();
    $g1->next();
    foreach ($g2 as $v);
}

function yieldFromNestedGenerator() {
    yield from yieldFromGenerator();
}

function runYieldFromNestedGenerator() {
    foreach (yieldFromNestedGenerator() as $k => $v);
}
