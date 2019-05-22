<?php

namespace App;

use Psr\Container\ContainerInterface;

class SimpleViewController
{
    /**
     * @var \Slim\Views\Twig
     */
    private $view;

    public function __construct(ContainerInterface $container)
    {
        $this->view = $container->get('view');
    }

    public function index($request, $response, $args)
    {
        return $this->view->render($response, 'simple_view.phtml', $args);
    }
}
