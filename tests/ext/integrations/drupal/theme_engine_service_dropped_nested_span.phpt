--TEST--
Drupal render span is not tagged with a dropped nested render's template, engine service (APMS-20395)
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
            if ($hook === 'block') {
                // Dropped after the tracing prehook ran, so the tracer is not limited;
                // active_span() now points at the OUTER render.
                \DDTrace\try_drop_span(\DDTrace\active_span());
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

    $engine = new Drupal\Core\Template\TwigThemeEngine();
    $engines = new Drupal\Core\Theme\ThemeEngineCollection(['twig' => $engine]);
    $themeManager = new Drupal\Core\Theme\ThemeManager($engines);

    // An outer span is required: dropping a stack's root span closes it instead.
    $root = DDTrace\start_span();
    $root->name = 'test.root';

    // Twig renders an embedded theme hook from inside the outer template.
    $engine->nested = function () use ($themeManager) {
        return $themeManager->render('block');
    };

    $themeManager->render('page');

    DDTrace\close_span();

    $spans = dd_trace_serialize_closed_spans();
    foreach ($spans as $span) {
        if ($span['name'] !== 'drupal.theme.render') {
            continue;
        }
        echo "surviving render span template.file = ",
            isset($span['meta']['drupal.template.file']) ? $span['meta']['drupal.template.file'] : '<none>',
            "\n";
    }
    dd_drupal_render_report($spans, ['Drupal\Core\Template\TwigThemeEngine::renderTemplate']);
}
?>
--EXPECTF--
[ddtrace] [error] [%d] Cannot run tracing closure for render(); spans out of sync; This message is only displayed once. Specify DD_TRACE_ONCE_LOGS=0 to show all messages.
surviving render span template.file = core/themes/claro/templates/page.html.twig
drupal.render.engine[twig] = 1
drupal.template.file[core/themes/claro/templates/page.html.twig] = 1
hook budget left [Drupal\Core\Template\TwigThemeEngine::renderTemplate]: yes
