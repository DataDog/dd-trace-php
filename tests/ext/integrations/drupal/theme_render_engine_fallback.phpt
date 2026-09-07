--TEST--
Drupal render span is tagged when core falls back to twig's render function (APMS-20395)
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
            return 'claro';
        }

        public function getEngine()
        {
            // No phptemplate_render_template() is ever declared.
            return 'phptemplate';
        }
    }

    class ThemeManager
    {
        public function getActiveTheme()
        {
            return new ActiveTheme();
        }

        public function render($hook, array $variables = [])
        {
            // ThemeManager::render() falls back to twig's render function when the active engine
            // has no {engine}_render_template() of its own.
            return twig_render_template("core/themes/claro/templates/$hook.html.twig", $variables);
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

    $themeManager = new Drupal\Core\Theme\ThemeManager();
    // Repeated so that a per-render install would show up as an exhausted hook budget.
    for ($i = 0; $i < 30; ++$i) {
        $themeManager->render('fallback');
    }

    dd_drupal_render_report(dd_trace_serialize_closed_spans(), [
        'twig_render_template',
        'phptemplate_render_template',
    ]);
}
?>
--EXPECT--
drupal.render.engine[phptemplate] = 30
drupal.template.file[core/themes/claro/templates/fallback.html.twig] = 30
hook budget left [twig_render_template]: yes
hook budget left [phptemplate_render_template]: yes
