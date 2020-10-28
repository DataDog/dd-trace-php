<?php

namespace App;

class FatalErrorController
{
    public function render()
    {
        header('Content-Type: text/plain');
        echo "This script will intentionally create a fatal error.\n";
        \DDTrace\Testing\trigger_error("Intentional E_ERROR", E_ERROR);
    }
}
