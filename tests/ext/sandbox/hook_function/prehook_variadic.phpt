--TEST--
DDTrace\hook_function prehook works with variadic functions
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_function('dump_all',
    function ($args) {
        echo "dump_all hooked.\n";
        assert($args == ['Hello', 'Datadog']);
    }
);

function dump_all()
{
    $args = func_get_args();
    if (!empty($args)) {
	    call_user_func_array('var_dump', $args);
    }
}

dump_all('Hello', 'Datadog');

?>
--EXPECT--
dump_all hooked.
string(5) "Hello"
string(7) "Datadog"

