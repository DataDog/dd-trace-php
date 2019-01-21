<?php

class Bootstrap extends Zend_Application_Bootstrap_Bootstrap
{
    protected function _initRoutes()
    {
        $router = Zend_Controller_Front::getInstance()->getRouter();
        $router->addRoute(
            'my_simple_view_route',
            new Zend_Controller_Router_Route(
                'simple_view',
                [
                    'controller' => 'simple',
                    'action' => 'view'
                ])
        );
    }
}
