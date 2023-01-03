<?php

$container = require __DIR__ . '/../app/bootstrap.php';

// Avoid spurious PDO instantiations appearing in traces within FileStorage::clean() (with SQLiteJournal)
\Nette\Caching\Storages\FileStorage::$gcProbability = 0;

$container->getByType(Nette\Application\Application::class)
    ->run();
