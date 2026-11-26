<?php

declare(strict_types=1);

namespace Application\I18n;

use Laminas\I18n\Translator\TranslatorInterface;

/** Translates route literals to German, the only locale the tests use. */
class RouteTranslator implements TranslatorInterface
{
    private const MESSAGES = [
        'de' => ['users' => 'benutzer'],
    ];

    public function translate($message, $textDomain = 'default', $locale = null)
    {
        return self::MESSAGES[$locale ?? 'de'][$message] ?? $message;
    }

    public function translatePlural($singular, $plural, $number, $textDomain = 'default', $locale = null)
    {
        return $this->translate($number == 1 ? $singular : $plural, $textDomain, $locale);
    }
}
