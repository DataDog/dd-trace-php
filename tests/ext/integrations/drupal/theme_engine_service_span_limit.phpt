--TEST--
Drupal render span is not tagged with a nested template past the span limit (APMS-20395)
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
            $render_function = [$this->themeEngines->get('twig'), 'renderTemplate'];
            return $render_function("core/themes/claro/templates/$hook", $variables);
        }
    }
}

namespace
{
    include __DIR__ . '/drupal_integration.inc';

    DDTrace\Integrations\Drupal\DrupalIntegration::init();

    $engine = new Drupal\Core\Template\TwigThemeEngine();
    $engines = new Drupal\Core\Theme\ThemeEngineCollection(['twig' => $engine]);
    $themeManager = new Drupal\Core\Theme\ThemeManager($engines);

    // Twig renders an embedded theme hook, i.e. a nested render during the outer renderTemplate().
    $engine->nested = function () use ($themeManager) {
        return $themeManager->render('block');
    };

    // Room for exactly one more span: the outer render gets a real one, the nested render only a
    // dummy that is never pushed, so it must not reach the outer's tag. init() forces >= 1500.
    ini_set('datadog.trace.spans_limit', 2);
    DDTrace\start_span();
    DDTrace\close_span();

    $rendered = $themeManager->render('page');

    // Proves the nested render really ran, so the tag assertion below cannot pass vacuously.
    echo "nested rendered: ", var_export(strpos($rendered, 'block.html.twig') !== false, true), "\n";
    echo "limited: ", var_export((bool) dd_trace_tracer_is_limited(), true), "\n";
    dd_drupal_render_report(dd_trace_serialize_closed_spans(), [
        'Drupal\Core\Template\TwigThemeEngine::renderTemplate',
    ]);
}
?>
--EXPECT--
nested rendered: true
limited: true
drupal.render.engine[twig] = 1
drupal.template.file[core/themes/claro/templates/page.html.twig] = 1
hook budget left [Drupal\Core\Template\TwigThemeEngine::renderTemplate]: yes
