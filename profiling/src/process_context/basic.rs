// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

// Basic profiler builds do not read the tracer's OTel process/thread context.
// Keep the same API so sampling and profiling remain available without the
// process-context reader or its protobuf dependency.
use super::{ProcessIdentityRef, ThreadContextRead};
use crate::profiling::profile_tags::UnifiedServiceTagSegment;
use std::sync::Arc;

#[derive(Default)]
pub(crate) struct ProcessContextCache;

impl ProcessContextCache {
    pub(crate) fn new() -> Self {
        Self
    }

    pub(crate) fn reset(&mut self) {}
}

pub(crate) fn initialize() {}
pub(crate) fn invalidate_before_fork() {}
pub(crate) fn process_tags() -> Option<String> {
    None
}
pub(crate) fn runtime_id() -> Option<String> {
    None
}

pub(crate) fn thread_context(identity: ProcessIdentityRef<'_>) -> ThreadContextRead {
    let tags = UnifiedServiceTagSegment::try_new(
        identity.service.unwrap_or_default(),
        identity.env.unwrap_or_default(),
        identity.version.unwrap_or_default(),
    )
    .map(Arc::new)
    .unwrap_or_else(|_| Arc::default());
    ThreadContextRead::Inactive(tags)
}
