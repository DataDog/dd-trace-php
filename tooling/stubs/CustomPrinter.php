<?php declare(strict_types=1);

use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

class CustomPrinter extends Standard
{
    protected function pStmt_Namespace(Stmt\Namespace_ $node)
    {
        $this->canUseSemicolonNamespaces = false;
        return parent::pStmt_Namespace($node);
    }
}
