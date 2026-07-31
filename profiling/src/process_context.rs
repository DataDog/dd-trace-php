// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#[derive(Debug, Default)]
pub(crate) struct ThreadContext {
    pub(crate) local_root_span_id: u64,
    pub(crate) span_id: u64,
    pub(crate) thread_id: Option<i64>,
    pub(crate) service: Option<String>,
    pub(crate) environment: Option<String>,
    pub(crate) version: Option<String>,
}

#[derive(Default)]
pub(crate) struct ProcessIdentity {
    pub(crate) service: Option<String>,
    pub(crate) environment: Option<String>,
    pub(crate) version: Option<String>,
}

#[derive(Clone, Copy, Default)]
pub(crate) struct ProcessIdentityRef<'a> {
    pub(crate) service: Option<&'a str>,
    pub(crate) environment: Option<&'a str>,
    pub(crate) version: Option<&'a str>,
}

pub(crate) enum ThreadContextRead {
    /// No valid OTel Thread Context is currently attached.
    Inactive,
    Active(ThreadContext),
}

#[path = "process_context/linux.rs"]
mod platform;

pub(crate) use platform::thread_context;
pub(crate) use platform::ProcessContextCache;
pub(crate) use platform::{identity, initialize, invalidate_before_fork, process_tags, runtime_id};
