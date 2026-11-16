--TEST--
Drupal 11.3+ renders through the theme engine service (APMS-20395)
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

    // Stands in for ThemeManager::$themeEngines (Drupal 11.3+); has() must not instantiate.
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
        // Protected upstream, so the prehook only reaches it when rebound to this scope.
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
            $render_function = [$this->themeEngines->get('twig'), 'renderTemplate'];
            return $render_function("core/themes/claro/templates/$hook", $variables);
        }
    }
}

namespace
{
    // twig.engine is still included on 11.3+, so the deprecated global exists but is dead.
    function twig_render_template($template_file, array $variables)
    {
        return "legacy $template_file";
    }

    include __DIR__ . '/drupal_integration.inc';

    DDTrace\Integrations\Drupal\DrupalIntegration::init();

    // Only touched after init(), so the engine class is autoloaded mid-request.
    var_dump(class_exists('Drupal\Core\Template\TwigThemeEngine', false));
    $engines = new Drupal\Core\Theme\ThemeEngineCollection([
        'twig' => new Drupal\Core\Template\TwigThemeEngine(),
    ]);

    $themeManager = new Drupal\Core\Theme\ThemeManager($engines);
    for ($i = 0; $i < 30; ++$i) {
        $themeManager->render('page');
    }

    dd_drupal_render_report(dd_trace_serialize_closed_spans(), [
        'Drupal\Core\Template\TwigThemeEngine::renderTemplate',
        'twig_render_template',
    ]);
}
?>
--EXPECT--
bool(false)
drupal.render.engine[twig] = 30
drupal.template.file[core/themes/claro/templates/page.html.twig] = 30
hook budget left [Drupal\Core\Template\TwigThemeEngine::renderTemplate]: yes
hook budget left [twig_render_template]: yes
