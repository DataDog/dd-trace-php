<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Avoid spurious PDO instantiations appearing in traces within FileStorage::clean() (with SQLiteJournal)
\Nette\Caching\Storages\FileStorage::$gcProbability = 0;

App\Bootstrap::boot()
    ->createContainer()
    ->getByType(Nette\Application\Application::class)
    ->run();
