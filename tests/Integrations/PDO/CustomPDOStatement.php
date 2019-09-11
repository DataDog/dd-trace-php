<?php

namespace DDTrace\Tests\Integrations\PDO;

class CustomPDOStatement
{
    private $query;

    public function __construct($query)
    {
        $this->query = $query;
    }

    public function __toString()
    {
        return $this->query;
    }
}
