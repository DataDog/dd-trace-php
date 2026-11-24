<?php

declare(strict_types=1);

namespace Application;

use Application\I18n\RouteTranslator;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\TranslatorAwareTreeRouteStack;

class Module
{
    public function getConfig(): array
    {
        /** @var array $config */
        $config = include __DIR__ . '/../config/module.config.php';
        return $config;
    }

    public function onBootstrap(MvcEvent $e): void
    {
        $router = $e->getRouter();
        if ($router instanceof TranslatorAwareTreeRouteStack) {
            $router->setTranslator(new RouteTranslator());
        }
    }
}
