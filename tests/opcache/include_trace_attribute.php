<?php

namespace Datadog;

final class PreloadedTraced
{
    const TAGS = ['component' => 'preloaded'];

    #[\DDTrace\Trace(name: "preloaded.method", resource: "rsrc", tags: self::TAGS)]
    public static function method()
    {
        $span = \DDTrace\active_span();
        echo "method: ", $span->name, " ", $span->resource, " ", $span->meta['component'] ?? 'no tags', "\n";
    }
}

trait PreloadedTracedTrait
{
    #[\DDTrace\Trace(name: "preloaded.trait")]
    public function viaTrait()
    {
        echo "trait: ", \DDTrace\active_span()->name, "\n";
    }
}

#[\DDTrace\Trace(name: "preloaded.function")]
function preloaded_function()
{
    echo "function: ", \DDTrace\active_span()->name, "\n";
}

#[\DDTrace\Trace(name: "preloaded.unresolvable", resource: \Not\Loaded::RESOURCE)]
function preloaded_unresolvable()
{
    // The resource argument cannot be evaluated during request startup, so it is skipped
    echo "unresolvable: ", \DDTrace\active_span()->name, " ", \DDTrace\active_span()->resource ?: "(no resource)", "\n";
}
