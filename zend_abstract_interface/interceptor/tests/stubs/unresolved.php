<?php

class TopLevel {
    function foo() {}
}

function defineFunc() {
    function aFunction() {}
}

function defineNormal() {
    class Normal {
        function foo() {}
    }
}

function defineInherited() {
    class Inherited extends stdClass {
        function bar() {}
    }
}

function defineDelayedInherited() {
    class Inherited extends Normal {
        function bar() {}
    }
}

function failDeclare() {
    set_error_handler(function() { throw new Exception; });

    try {
        $errorline = __LINE__ + 1;
        class Inherited extends TopLevel {
            function foo($x) {}
        }
    } catch (Exception $e) {
        if ($e->getLine() != $errorline) {
            return null;
        }
        return true;
    }
    return false;
}

function doAlias() {
    class_alias("TopLevel", "Aliased");
}

function doEval() {
    eval("function dynamicFunction() {}");
}