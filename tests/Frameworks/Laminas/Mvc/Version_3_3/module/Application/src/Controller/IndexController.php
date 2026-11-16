<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        return new ViewModel();
    }

    public function helloAction()
    {
        $response = $this->getResponse();
        $response->setContent('hello from skeleton-style /application[/:action]');

        return $response;
    }
}
