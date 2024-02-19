<?php

namespace DDTrace\Util;

use DDTrace\RootSpanData;
use DDTrace\SpanData;

class Common
{
    // When modifying this method, please also pay attention to the tests/ext/orphans.phpt test and update it accordingly if needed.
    public static function handleOrphan(SpanData $span)
    {
        if (dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
            && $span instanceof RootSpanData
            && empty($span->parentId)
        ) {
            $prioritySampling = \DDTrace\get_priority_sampling();
            if ($prioritySampling == DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP
                || $prioritySampling == DD_TRACE_PRIORITY_SAMPLING_USER_KEEP
                || $prioritySampling == DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT
            ) {
                \DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
            }
        }
    }
}
