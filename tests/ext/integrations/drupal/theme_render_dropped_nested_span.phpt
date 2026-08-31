--TEST--
Drupal legacy render span is not tagged with a dropped nested render's template (APMS-20395)
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
            if ($hook === 'block') {
                // Dropped after the prehook ran, so the hook is installed and active_span() is the OUTER render.
                \DDTrace\try_drop_span(\DDTrace\active_span());
            }

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
            $nested = $variables['nested'];
            $rendered .= $nested();
        }
        return $rendered;
    }

    include __DIR__ . '/drupal_integration.inc';

    DDTrace\Integrations\Drupal\DrupalIntegration::init();

    // An outer span is required: dropping a stack's root span closes it instead.
    $root = DDTrace\start_span();
    $root->name = 'test.root';

    $themeManager = new Drupal\Core\Theme\ThemeManager();
    $themeManager->render('page', ['nested' => function () use ($themeManager) {
        return $themeManager->render('block');
    }]);

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
    dd_drupal_render_report($spans, ['twig_render_template']);
}
?>
--EXPECTF--
[ddtrace] [error] [%d] Cannot run tracing closure for render(); spans out of sync; This message is only displayed once. Specify DD_TRACE_ONCE_LOGS=0 to show all messages.
surviving render span template.file = core/themes/olivero/templates/page.html.twig
drupal.render.engine[twig] = 1
drupal.template.file[core/themes/olivero/templates/page.html.twig] = 1
hook budget left [twig_render_template]: yes
