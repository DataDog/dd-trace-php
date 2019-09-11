<?php

namespace DDTrace\Tests\Integrations\PDO;

class BrokenPDOStatement
{
    private $query;

    public function __construct($query)
    {
        $this->query = $query;
    }

    public function getQuery()
    {
        return $this->query;
    }

    // No __toString() magic method
}
