<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;

class MigrateController extends Controller
{
    public function migrate()
    {
        // If there are migrations to run, they will be run.
        Artisan::call('migrate --force');
    }
}
