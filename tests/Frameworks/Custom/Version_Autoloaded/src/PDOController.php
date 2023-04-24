<?php

namespace App;

class PDOController
{
    public function render()
    {
        new \PDO("mysql:");
        echo 'This is a string';
    }
}
