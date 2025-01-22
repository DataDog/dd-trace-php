<?php

namespace DDTrace\Tests\Integrations\Mongo\Utils;

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