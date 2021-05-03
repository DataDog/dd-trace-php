<?php

namespace DDTrace;

class Scope
{
    private $span;

    public function __construct($span)
    {
        $this->span = $span;
    }

    public function getSpan()
    {
        return $this->span;
    }

    public function close()
    {
    }
}
