<?php

namespace DDTrace\Tests\Integrations\PDO;

class CustomPDO extends \PDO
{
    #[\ReturnTypeWillChange]
    public function prepare($statement, $options = [])
    {
        $query = $statement instanceof BrokenPDOStatement
            ? $statement->getQuery()
            : (string) $statement;
        return parent::prepare($query, $options);
    }
}
