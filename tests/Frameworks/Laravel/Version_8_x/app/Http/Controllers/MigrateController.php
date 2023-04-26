<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;

class MigrateController extends Controller
{
    public function migrate()
    {
        // If there are migrations to run, they will be run.
        // Since we are using a production environment, we need to force the migrations.
        Artisan::call('migrate --force');
        fwrite(STDERR, Artisan::output());
    }
}
