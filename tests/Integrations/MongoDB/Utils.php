<?php

namespace DDTrace\Tests\Integrations\MongoDB;

class AQuery
{
    public $brand;

    public function __construct($brand = 'ferrari')
    {
        $this->brand = $brand;
    }
}

class AnObject
{
}