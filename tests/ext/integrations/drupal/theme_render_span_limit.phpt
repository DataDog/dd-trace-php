--TEST--
Drupal legacy render span is not tagged with a nested template past the span limit (APMS-20395)
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
            return twig_render_template("core/themes/olivero/templates/$hook.html.twig", $variables);
        }
    }
}

namespace
{
    function twig_render_template($template_file, array $variables)
    {
        $rendered = "rendered $template_file";
        if (isset($variables['nested'])) {
            // A template embedding another theme hook: a nested render from the render function.
            $nested = $variables['nested'];
            $rendered .= $nested();
        }
        return $rendered;
    }

    include __DIR__ . '/drupal_integration.inc';

    DDTrace\Integrations\Drupal\DrupalIntegration::init();

    $themeManager = new Drupal\Core\Theme\ThemeManager();

    // Room for exactly one more span: the outer render gets a real one, the nested render only a
    // dummy that is never pushed, so it must not reach the outer's tag. init() forces >= 1500.
    ini_set('datadog.trace.spans_limit', 2);
    DDTrace\start_span();
    DDTrace\close_span();

    $rendered = $themeManager->render('page', ['nested' => function () use ($themeManager) {
        return $themeManager->render('block');
    }]);

    // Proves the nested render really ran, so the tag assertion below cannot pass vacuously.
    echo "nested rendered: ", var_export(strpos($rendered, 'block.html.twig') !== false, true), "\n";
    echo "limited: ", var_export((bool) dd_trace_tracer_is_limited(), true), "\n";
    dd_drupal_render_report(dd_trace_serialize_closed_spans(), ['twig_render_template']);
}
?>
--EXPECT--
nested rendered: true
limited: true
drupal.render.engine[twig] = 1
drupal.template.file[core/themes/olivero/templates/page.html.twig] = 1
hook budget left [twig_render_template]: yes
