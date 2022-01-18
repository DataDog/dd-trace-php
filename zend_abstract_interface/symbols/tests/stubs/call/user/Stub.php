<?php
namespace DDTraceTesting {
    class Base {
        public static function staticPublicFunction() {
            return 24;
        }

        protected static function staticProtectedFunction() {
            return 24;
        }

        private static function staticPrivateFunction() {
            return 24;
        }

        public function publicFunction() {
            return 24;
        }

        public function protectedFunction() {
            return 24;
        }

        public function privateFunction() {
            return 24;
        }
    }

    class Stub extends Base {
        public static function staticPublicFunction() {
            return 42;
        }

        protected static function staticProtectedFunction() {
            return 42;
        }

        private static function staticPrivateFunction() {
            return 42;
        }

        public function publicFunction() {
            return 42;
        }

        public function protectedFunction() {
            return 42;
        }

        public function privateFunction() {
            return 42;
        }
    }

    class NoMagicCall {
        public function __call($name, $args) {
            /* Will not be called for non-existent case */
            return 42;
        }
    }

    abstract class NoAbstractCall {
        public abstract function abstractFunction();
    }

    class NoStaticMismatch {
        public function nonStaticFunction() {}
    }

    class NoExceptionLeakage {
        public static function throwsException() {
            throw new RuntimeException();
        }
    }

    function stub($param) {
        return strlen($param);
    }
    
    function noargs() {}
}

namespace {
    function stub($param) {
        return \DDTraceTesting\stub($param);
    }
}
?>
