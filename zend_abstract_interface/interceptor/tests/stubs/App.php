<?php

namespace MyDatadog\Foo;

class App
{
    public function testing()
    {
        return true;
    }

    public function waitTillRuntimeToHookMe()
    {
        return "I'm late to the party";
    }

    public function run()
    {
        var_dump($this->testing());
        var_dump($this->waitTillRuntimeToHookMe());
    }
}

function my_func()
{
    return "Datadog";
}

function wait_till_runtime_to_hook_me_too()
{
    return "I'm also late to the party";
}
