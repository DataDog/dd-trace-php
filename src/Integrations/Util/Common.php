<?php

namespace DDTrace\Util;

use DDTrace\Log\Logger;
use DDTrace\RootSpanData;
use DDTrace\SpanData;

class Common
{
    public static function handleOrphan(SpanData $span)
    {

        Logger::get()->debug('PredisIntegration::handleOrphan');
        Logger::get()->debug('DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS: ' . dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS"));
        Logger::get()->debug('get_priority_sampling: ' . \DDTrace\get_priority_sampling());
        Logger::get()->debug('span instanceof RootSpanData: ' . ($span instanceof RootSpanData ? $span->traceId : 'false'));
        Logger::get()->debug('span samplingPriority: ' . ($span instanceof RootSpanData ? $span->samplingPriority : 'false'));
        Logger::get()->debug('empty($span->parentId): ' . empty($span->parentId));
        Logger::get()->debug('DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT: ' . \DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
        Logger::get()->debug('DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP: ' . \DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP);
        Logger::get()->debug('DD_TRACE_PRIORITY_SAMPLING_USER_KEEP: ' . \DD_TRACE_PRIORITY_SAMPLING_USER_KEEP);
        if (dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
            && (
                \DDTrace\get_priority_sampling() == DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP
                || \DDTrace\get_priority_sampling() == DD_TRACE_PRIORITY_SAMPLING_USER_KEEP
            ) && $span instanceof RootSpanData && empty($span->parentId)
        ) {
            \DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
        }
    }
}
