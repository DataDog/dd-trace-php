<?php

use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Storage\Session as SessionStorage;
use Laminas\Db\Adapter\Adapter;

return [
    'db' => [
        'driver' => 'Pdo',
        'dsn' => 'mysql:dbname=test;host=mysql-integration',
        'username' => 'test',
        'password' => 'test',
    ],
    'service_manager' => [
        'factories' => [
            Adapter::class => function ($container) {
                return new Adapter($container->get('config')['db']);
            },
            AuthenticationService::class => function ($container) {
                $storage = new SessionStorage();
                return new AuthenticationService($storage);
            },
        ],
    ],
];
