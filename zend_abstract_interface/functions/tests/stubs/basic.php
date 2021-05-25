<?php

namespace Zai\Functions\Test;

function return_arg($arg)
{
    return $arg;
}

function returns_true()
{
    return true;
}

function throws_exception()
{
    throw new \Exception('Oops!');
}
