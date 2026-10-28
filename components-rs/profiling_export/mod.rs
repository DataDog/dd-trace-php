//! Private, lockstep Rust ABI between the SSI extension and common runtime.
//!
//! Both sides must use the same compiler, target configuration and shared type
//! definitions. This is not an API for independently versioned Rust libraries.
//! Profile operations and destruction belong to the runtime; the PHP extension
//! retains capture, collector/upload scheduling, and fork coordination.

use libdd_common::tag::Tag;
use libdd_profiling::api::{Period, Sample, SampleType, UpscalingInfo};
use libdd_profiling::internal::Profile as InternalProfile;
use std::mem::ManuallyDrop;
use std::num::NonZeroI64;
use std::path::PathBuf;
use std::time::{Duration, SystemTime};

#[cfg(feature = "profiling-runtime")]
mod implementation;
#[cfg(feature = "profiling-runtime")]
use implementation as ffi;
#[cfg(not(feature = "profiling-runtime"))]
use imports as ffi;

// Local products call the implementation directly. SSI calls the matching
// common DSO, without exposing unsafe calls to the collector or uploader.
macro_rules! runtime_call {
    ($name:ident($($arg:expr),* $(,)?)) => {{
        #[cfg(feature = "profiling-runtime")]
        { ffi::$name($($arg),*) }
        #[cfg(not(feature = "profiling-runtime"))]
        // SAFETY: SSI co-builds both packages with the same compiler, target,
        // dependencies and type definitions. Both DSOs remain loaded while
        // profiles, returned buffers and errors are used and destroyed.
        unsafe { ffi::$name($($arg),*) }
    }};
}

/// A profile whose implementation, including cleanup, belongs to the runtime.
///
/// This preserves the existing inline, by-value ownership without another heap
/// allocation. ManuallyDrop prevents abandoned profiles (including rejected
/// uploads) from pulling InternalProfile's zstd destruction code into SSI's PHP
/// extension. Do not expose the inner value or derive Clone/Copy/Deref.
#[repr(transparent)]
pub struct Profile {
    inner: ManuallyDrop<InternalProfile>,
}

impl Profile {
    pub fn try_new(
        sample_types: &[SampleType],
        period: Option<Period>,
        start_time: SystemTime,
    ) -> anyhow::Result<Self> {
        runtime_call!(ddog_php_prof_new(sample_types, period, start_time))
    }

    /// Input borrows last only for this call; retained data is copied/interned.
    pub fn try_add_sample(
        &mut self,
        sample: Sample<'_>,
        timestamp: Option<NonZeroI64>,
    ) -> anyhow::Result<()> {
        runtime_call!(ddog_php_prof_add_sample(self, sample, timestamp))
    }

    pub fn add_upscaling_rule(
        &mut self,
        value_offsets: &[usize],
        label_name: &str,
        label_value: &str,
        info: UpscalingInfo,
    ) -> anyhow::Result<()> {
        runtime_call!(ddog_php_prof_add_upscaling_rule(
            self,
            value_offsets,
            label_name,
            label_value,
            info,
        ))
    }

    pub fn add_endpoint(&mut self, local_root_span_id: u64, endpoint: &str) -> anyhow::Result<()> {
        runtime_call!(ddog_php_prof_add_endpoint(
            self,
            local_root_span_id,
            endpoint
        ))
    }

    pub fn add_endpoint_count(&mut self, endpoint: &str, count: i64) -> anyhow::Result<()> {
        runtime_call!(ddog_php_prof_add_endpoint_count(self, endpoint, count))
    }

    // Existing collector tests inspect aggregation only in locally implemented
    // test builds. No inspection API or InternalProfile access is exported to SSI.
    #[cfg(all(test, feature = "profiling-runtime"))]
    pub(crate) fn only_for_testing_num_aggregated_samples(&self) -> usize {
        self.inner.only_for_testing_num_aggregated_samples()
    }
}

impl Drop for Profile {
    fn drop(&mut self) {
        // SAFETY: only the runtime constructs Profile, with a live inner value.
        // This runs once, and the inner ManuallyDrop suppresses automatic field
        // destruction. Consuming runtime calls suppress this wrapper's Drop
        // before taking ownership of the inner value instead.
        unsafe { ffi::ddog_php_prof_drop(self) }
    }
}

#[repr(C)]
pub enum AgentEndpoint {
    Uri(String),
    Socket(PathBuf),
}

#[repr(C)]
pub struct Upload {
    pub profile: Profile,
    pub end_time: SystemTime,
    pub duration: Option<Duration>,
    pub endpoint: AgentEndpoint,
    pub library_name: &'static str,
    pub library_version: &'static str,
    pub tags: Vec<Tag>,
    pub internal_metadata: Option<serde_json::Value>,
    pub info: Option<serde_json::Value>,
    pub process_tags: Option<String>,
}

#[cfg(not(feature = "profiling-runtime"))]
mod imports {
    use super::*;

    extern "Rust" {
        pub fn ddog_php_prof_new(
            sample_types: &[SampleType],
            period: Option<Period>,
            start_time: SystemTime,
        ) -> anyhow::Result<Profile>;
        pub fn ddog_php_prof_add_sample(
            profile: &mut Profile,
            sample: Sample<'_>,
            timestamp: Option<NonZeroI64>,
        ) -> anyhow::Result<()>;
        pub fn ddog_php_prof_add_upscaling_rule(
            profile: &mut Profile,
            value_offsets: &[usize],
            label_name: &str,
            label_value: &str,
            info: UpscalingInfo,
        ) -> anyhow::Result<()>;
        pub fn ddog_php_prof_add_endpoint(
            profile: &mut Profile,
            local_root_span_id: u64,
            endpoint: &str,
        ) -> anyhow::Result<()>;
        pub fn ddog_php_prof_add_endpoint_count(
            profile: &mut Profile,
            endpoint: &str,
            count: i64,
        ) -> anyhow::Result<()>;
        // Destructor-only; destroys the inner value, not the wrapper.
        pub fn ddog_php_prof_drop(profile: &mut Profile);
        pub fn ddog_php_prof_serialize_and_upload(upload: Upload) -> anyhow::Result<u16>;
        pub fn ddog_php_prof_serialize(profile: Profile) -> anyhow::Result<Vec<u8>>;
    }
}

/// Consumes the profile on the uploader thread; no exporter survives the call.
pub fn serialize_and_upload(upload: Upload) -> anyhow::Result<u16> {
    runtime_call!(ddog_php_prof_serialize_and_upload(upload))
}

/// The direct pprof-file output path uses the same encoding owner, without HTTP.
pub fn serialize(profile: Profile) -> anyhow::Result<Vec<u8>> {
    runtime_call!(ddog_php_prof_serialize(profile))
}

#[cfg(all(test, feature = "profiling-runtime"))]
mod tests;
