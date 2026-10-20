<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Http\Request;
use Laminas\Mvc\Controller\AbstractActionController;

class DynamicPathController extends AbstractActionController
{
    public function ambiguousAction()
    {
        $routeMatch = $this->getEvent()->getRouteMatch();
        $name = $routeMatch->getParam('name');
        $ext = $routeMatch->getParam('ext');

        $response = $this->getResponse();
        $response->setContent("$name/$ext");
        return $response;
    }

    /** Matches another URL with the router after the request was routed. */
    public function rematchAction()
    {
        $other = new Request();
        $other->setUri('http://localhost/normalized-name/alice');
        $this->getEvent()->getRouter()->match($other);

        $response = $this->getResponse();
        $response->setContent('ok');
        return $response;
    }

    public function indexAction()
    {
        $response = $this->getResponse();
        $response->setContent('ok');
        return $response;
    }
}
