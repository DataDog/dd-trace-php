--TEST--
Drupal render hook is removed when the render span is dropped (APMS-20395)
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
    class ActiveTheme
    {
        public function getName()
        {
            return 'olivero';
        }

        public function getEngine()
        {
            return 'twig';
        }
    }

    // Drupal <= 11.2 shape: no theme engine service, the global render function is used.
    class ThemeManager
    {
        public function getActiveTheme()
        {
            return new ActiveTheme();
        }

        public function render($hook, array $variables = [])
        {
            if ($hook === 'dropped') {
                // A sampling filter or another integration can drop the render span; the end hook
                // must still run and remove the render hook.
                \DDTrace\try_drop_span(\DDTrace\active_span());
                return false;
            }

            return twig_render_template("core/themes/olivero/templates/$hook.html.twig", $variables);
        }
    }
}

namespace
{
    function twig_render_template($template_file, array $variables)
    {
        return "rendered $template_file";
    }

    include __DIR__ . '/drupal_integration.inc';

    DDTrace\Integrations\Drupal\DrupalIntegration::init();

    // The render spans must not be root spans: dropping a root span rejects the trace instead
    // of marking the span dropped.
    $root = DDTrace\start_span();
    $root->name = 'test.root';

    $themeManager = new Drupal\Core\Theme\ThemeManager();
    for ($i = 0; $i < 30; ++$i) {
        $themeManager->render('dropped');
    }
    // A leak above exhausts the budget, so this render can no longer tag its own template.
    $themeManager->render('page');

    DDTrace\close_span();

    $spans = dd_trace_serialize_closed_spans();
    $names = [];
    foreach ($spans as $span) {
        $names[$span['name']] = (isset($names[$span['name']]) ? $names[$span['name']] : 0) + 1;
    }
    ksort($names);
    foreach ($names as $name => $count) {
        echo "span[$name] = $count\n";
    }

    dd_drupal_render_report($spans, ['twig_render_template']);
}
?>
--EXPECT--
span[drupal.theme.render] = 1
span[test.root] = 1
drupal.render.engine[twig] = 1
drupal.template.file[core/themes/olivero/templates/page.html.twig] = 1
hook budget left [twig_render_template]: yes
