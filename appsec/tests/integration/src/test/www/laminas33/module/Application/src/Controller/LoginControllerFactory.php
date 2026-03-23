<?php

declare(strict_types=1);

namespace Application\Controller;

use Interop\Container\ContainerInterface;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\ServiceManager\Factory\FactoryInterface;

class LoginControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $dbAdapter = $container->get(Adapter::class);
        $authService = $container->get(AuthenticationService::class);
        $usersTable = new TableGateway('users', $dbAdapter);

        return new LoginController($dbAdapter, $authService, $usersTable);
    }
}
