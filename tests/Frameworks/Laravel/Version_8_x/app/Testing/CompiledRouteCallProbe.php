<?php

namespace App\Testing;

use Illuminate\Routing\Route;

class CompiledRouteCallProbe extends Route
{
    public static $calls = 0;

    public function getCompiled()
    {
        self::$calls++;
        return parent::getCompiled();
    }
}
