--TEST--
Drupal render spans keep their own template when a sub-element renders first (APMS-20395)
--SKIPIF--
<?php
require __DIR__ . '/drupal_root.inc';
if (!dd_drupal_tracer_root()) {
    die("skip tracer sources not found from " . __DIR__ . "\n");
}
?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_LOG_LEVEL=warn,startup=off
--INI--
datadog.trace.hook_limit=20
--FILE--
<?php

namespace Drupal\Core\Theme
{
    interface ThemeEngineInterface
    {
        public function renderTemplate(string $template_file, array $variables): string;
    }

    class ActiveTheme
    {
        public function getName()
        {
            return 'claro';
        }

        public function getEngine()
        {
            return 'twig';
        }
    }

    class ThemeEngineCollection
    {
        private $engines;

        public function __construct(array $engines)
        {
            $this->engines = $engines;
        }

        public function has($name)
        {
            return isset($this->engines[$name]);
        }

        public function get($name)
        {
            return $this->engines[$name];
        }
    }

    class ThemeManager
    {
        protected $themeEngines;

        public function __construct(ThemeEngineCollection $themeEngines)
        {
            $this->themeEngines = $themeEngines;
        }

        public function getActiveTheme()
        {
            return new ActiveTheme();
        }

        public function render($hook, array $variables = [])
        {
            // A preprocess callback renders a sub-element before the outer template itself
            // (ThemeManager.php:366 vs :428), so the child's renderTemplate() fires first.
            // This is why the tag cannot be first-write-wins.
            if ($hook === 'page') {
                $this->render('child');
            }
            $render_function = [$this->themeEngines->get('twig'), 'renderTemplate'];
            return $render_function("core/themes/claro/templates/$hook", $variables);
        }
    }
}

namespace
{
    include __DIR__ . '/drupal_integration.inc';

    DDTrace\Integrations\Drupal\DrupalIntegration::init();

    $engines = new Drupal\Core\Theme\ThemeEngineCollection([
        'twig' => new Drupal\Core\Template\TwigThemeEngine(),
    ]);
    $themeManager = new Drupal\Core\Theme\ThemeManager($engines);

    for ($i = 0; $i < 10; ++$i) {
        $themeManager->render('page');
    }

    dd_drupal_render_report(dd_trace_serialize_closed_spans(), [
        'Drupal\Core\Template\TwigThemeEngine::renderTemplate',
    ]);
}
?>
--EXPECT--
drupal.render.engine[twig] = 20
drupal.template.file[core/themes/claro/templates/child.html.twig] = 10
drupal.template.file[core/themes/claro/templates/page.html.twig] = 10
hook budget left [Drupal\Core\Template\TwigThemeEngine::renderTemplate]: yes
