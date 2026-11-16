--TEST--
Drupal renders that never call the render function do not mis-tag later ones (APMS-20395)
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
            if ($hook === 'missing') {
                // The theme registry has no implementation for the hook: ThemeManager::render()
                // returns FALSE without ever calling the render function.
                return false;
            }

            $rendered = twig_render_template("core/themes/olivero/templates/$hook.html.twig", $variables);
            if ($hook === 'page') {
                $rendered .= $this->render('block');
            }
            return $rendered;
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
    // Renders that never reach the render function must not exhaust the hook budget...
    for ($i = 0; $i < 30; ++$i) {
        $themeManager->render('missing');
    }
    // ...nor leave a hook behind that later mis-tags an unrelated render.
    for ($i = 0; $i < 30; ++$i) {
        $themeManager->render('page');
    }

    // Only the legacy global is installed on this shape, so it is the only budget to probe.
    dd_drupal_render_report(dd_trace_serialize_closed_spans(), ['twig_render_template']);
}
?>
--EXPECT--
drupal.render.engine[twig] = 90
drupal.template.file[<missing>] = 30
drupal.template.file[core/themes/olivero/templates/block.html.twig] = 30
drupal.template.file[core/themes/olivero/templates/page.html.twig] = 30
hook budget left [twig_render_template]: yes
