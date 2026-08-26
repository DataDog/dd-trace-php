<?php

declare(strict_types=1);

namespace Application;

use Laminas\I18n\Translator\Translator;
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

    public function onBootstrap(MvcEvent $event): void
    {
        $router = $event->getRouter();
        if (! $router instanceof TranslatorAwareTreeRouteStack) {
            return;
        }

        $translator = new Translator();
        $translator->setLocale('en');
        $translator->addTranslationFile(
            'phparray',
            __DIR__ . '/../lang/en.php',
            'default',
            'en'
        );
        $router->setTranslator($translator);
    }
}
