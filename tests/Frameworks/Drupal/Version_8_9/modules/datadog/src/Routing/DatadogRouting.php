<?php

namespace Drupal\datadog\Routing;

use Symfony\Component\Routing\RouteCollection;

/**
 * Class DatadogRouting
 *
 * @package Drupal\datadog\Routing
 */
class DatadogRouting
{
    /**
     * Dynamically generate the routes for the entity details.
     *
     * @return \Symfony\Component\Routing\RouteCollection
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function routes()
    {
        $collection = new RouteCollection();
        // I want to add three routes:
        // - /simple?key=value&pwd=should_redact
        // - /simple_view?key=value&pwd=should_redact
        // - /error?key=value&pwd=should_redact
        $collection->add('datadog.simple', $this->simpleRoute());
        $collection->add('datadog.simple_view', $this->simpleViewRoute());
        $collection->add('datadog.error', $this->errorRoute());
    }

    /**
     * @return \Symfony\Component\Routing\Route
     */
    protected function simpleRoute()
    {
        return new Route(
            'simple',
            [
                '_controller' => '\Drupal\datadog\Controller\DatadogController::simple',
                '_title' => 'Simple route',
            ],
            [
                '_permission' => 'access content',
            ]
        );
    }

    /**
     * @return \Symfony\Component\Routing\Route
     */
    protected function simpleViewRoute()
    {
        return new Route(
            'simple_view',
            [
                '_controller' => '\Drupal\datadog\Controller\DatadogController::simpleView',
                '_title' => 'Simple view route',
            ],
            [
                '_permission' => 'access content',
            ]
        );
    }

    /**
     * @return \Symfony\Component\Routing\Route
     */
    protected function errorRoute()
    {
        return new Route(
            'error',
            [
                '_controller' => '\Drupal\datadog\Controller\DatadogController::error',
                '_title' => 'Error route',
            ],
            [
                '_permission' => 'access content',
            ]
        );
    }
}
