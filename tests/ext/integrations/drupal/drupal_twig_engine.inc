<?php

namespace Drupal\Core\Template;

/**
 * Autoloaded on demand so the interface hook has to resolve against a class declared
 * after DrupalIntegration::init() ran, as it does in a real Drupal request.
 *
 * Core appends the extension inside renderTemplate() and passes $template_file without
 * it (TwigThemeEngine::EXTENSION, ThemeManager sets $extension = '' on this path).
 */
class TwigThemeEngine implements \Drupal\Core\Theme\ThemeEngineInterface
{
    const EXTENSION = '.html.twig';

    /** @var null|callable Invoked mid-render to model a template embedding another theme hook. */
    public $nested;

    public function renderTemplate(string $template_file, array $variables): string
    {
        $rendered = 'rendered ' . $template_file . self::EXTENSION;
        if ($this->nested) {
            $nested = $this->nested;
            $this->nested = null;
            $rendered .= $nested();
        }
        return $rendered;
    }
}
