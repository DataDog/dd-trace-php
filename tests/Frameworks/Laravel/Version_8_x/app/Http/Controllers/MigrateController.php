<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class MigrateController extends Controller
{
    public function migrate()
    {
        // If there are migrations to run, they will be run.
        // Since we are using a production environment, we need to force the migrations.
        // Check if the table 'jobs' exists
        // If it doesn't, we need to migrate
        // Otherwise, do nothing
        $result = $this->connection()->query("SHOW TABLES LIKE 'jobs'");
        if ($result->rowCount() === 0) {
            //Artisan::call('migrate --force');
            //file_put_contents('migrateDebug.txt', 'Migrated.' . PHP_EOL, FILE_APPEND);
            //file_put_contents('migrateDebug.txt', Artisan::output(), FILE_APPEND);

            // Create the 'jobs' table manually
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        } else {
            //file_put_contents('migrateDebug.txt', 'No migrations to run.' . PHP_EOL, FILE_APPEND);
        }

        return __METHOD__;
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }
}
