<?php

namespace FooBar {
    class Exception extends \Exception
    {
    }

    function throwAndCatch()
    {
        try {
            throw new Exception();
        } catch (Exception $e) {
            // I do not care ;-)
        }
    }
}

namespace {
    \FooBar\throwAndCatch();
}
