<?php

namespace FooBar {
    class Exception extends \Exception
    {
    }

    function throwAndCatch()
    {
        for ($i = 0; $i <= 10; $i++) {
            try {
                throw new Exception();
            } catch (Exception $e) {
                // I do not care ;-)
            }
        }
    }
}

namespace {
    \FooBar\throwAndCatch();
}
