// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#[cfg(target_os = "linux")]
#[derive(Debug, Default)]
pub(crate) struct ThreadContext {
    pub(crate) local_root_span_id: u64,
    pub(crate) span_id: u64,
    pub(crate) thread_id: Option<i64>,
    pub(crate) unified_service_tags:
        std::sync::Arc<crate::profiling::profile_tags::UnifiedServiceTagSegment>,
}

#[derive(Debug, Default)]
pub(crate) struct ProcessIdentity {
    pub(crate) service: Option<String>,
    pub(crate) env: Option<String>,
    pub(crate) version: Option<String>,
}

#[cfg(target_os = "linux")]
#[derive(Clone, Copy, Default)]
pub(crate) struct ProcessIdentityRef<'a> {
    pub(crate) service: Option<&'a str>,
    pub(crate) env: Option<&'a str>,
    pub(crate) version: Option<&'a str>,
}

#[cfg(target_os = "linux")]
pub(crate) enum ThreadContextRead {
    /// No valid OTel Thread Context is currently attached.
    Inactive(std::sync::Arc<crate::profiling::profile_tags::UnifiedServiceTagSegment>),
    Active(ThreadContext),
}

#[cfg(target_os = "linux")]
#[path = "process_context/linux.rs"]
mod platform;

#[cfg(target_os = "linux")]
pub(crate) use platform::thread_context;
#[cfg(target_os = "linux")]
pub(crate) use platform::ProcessContextCache;
#[cfg(target_os = "linux")]
pub(crate) use platform::{initialize, invalidate_before_fork, process_tags, runtime_id};
