<?php

function function_that_calls_a_function_that_triggers_an_error()
{
    do_trigger_error();
}

function do_trigger_error()
{
    trigger_error("Triggered error in function", E_USER_ERROR);
}

function function_that_calls_a_function_that_throws_an_exception()
{
    do_throw_exception();
}

function do_throw_exception()
{
    throw new Exception("Exception in external file");
}
