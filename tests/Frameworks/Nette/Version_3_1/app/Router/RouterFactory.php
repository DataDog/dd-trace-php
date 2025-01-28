<?php

declare(strict_types=1);

namespace App\Router;

use Nette;
use Nette\Application\Routers\RouteList;


final class RouterFactory
{
    use Nette\StaticClass;

    public static function createRouter(): RouteList
    {
        $router = new RouteList;
        $router->addRoute('/simple', 'Homepage:simple');
        $router->addRoute('/simple_view', 'Homepage:simpleView');
        $router->addRoute('/error', 'Homepage:errorView');
        $router->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');
        return $router;
    }
}
