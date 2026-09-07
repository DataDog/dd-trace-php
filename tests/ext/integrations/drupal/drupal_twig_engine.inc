<?php

namespace Drupal\Core\Template;

// Autoloaded on demand so the interface hook resolves against a class declared after init(),
// as in a real request. Core passes $template_file without the extension; renderTemplate() appends it.
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
