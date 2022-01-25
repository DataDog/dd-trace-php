<?php
namespace DDTraceTesting {

    class Stub {
        public static $self;

        public function testRebinding() {
            return function() {
                if (!$this instanceof Rebind) {
                    throw new \RuntimeException();
                }

                if ($this == Stub::$self) {
                    throw new \RuntimeException();
                }
            };
        }

        public function testBinding() {
            return function() {
                if (!$this instanceof Stub) {
                    throw new \RuntimeException();
                }

                if ($this != Stub::$self) {
                    throw new \RuntimeException();
                }
            };
        }
    }

    class Rebind extends Stub {}

    function closureTestRebinding() {
        Stub::$self = new Stub;

        \ddtrace_testing_closure_intercept(new Rebind, Stub::$self->testRebinding());
    }

    function closureTestBinding() {
        Stub::$self = new Stub;

        \ddtrace_testing_closure_intercept(null, Stub::$self->testBinding());
    }
}
?>
