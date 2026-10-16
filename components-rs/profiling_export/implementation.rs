use super::{AgentEndpoint, InternalProfile, Profile, Upload};
use libdd_profiling::api::{Period, Sample, SampleType, UpscalingInfo};
use libdd_profiling::exporter::{config, ProfileExporter};
use std::borrow::Cow;
use std::mem::ManuallyDrop;
use std::num::NonZeroI64;
use std::time::SystemTime;

#[no_mangle]
pub extern "Rust" fn ddog_php_prof_new(
    sample_types: &[SampleType],
    period: Option<Period>,
    start_time: SystemTime,
) -> anyhow::Result<Profile> {
    let mut inner = InternalProfile::try_new(sample_types, period)?;
    inner.set_start_time(start_time)?;
    Ok(Profile {
        inner: ManuallyDrop::new(inner),
    })
}

#[no_mangle]
pub extern "Rust" fn ddog_php_prof_add_sample(
    profile: &mut Profile,
    sample: Sample<'_>,
    timestamp: Option<NonZeroI64>,
) -> anyhow::Result<()> {
    profile.inner.try_add_sample(sample, timestamp)
}

#[no_mangle]
pub extern "Rust" fn ddog_php_prof_add_upscaling_rule(
    profile: &mut Profile,
    value_offsets: &[usize],
    label_name: &str,
    label_value: &str,
    info: UpscalingInfo,
) -> anyhow::Result<()> {
    profile
        .inner
        .add_upscaling_rule(value_offsets, label_name, label_value, info)
}

#[no_mangle]
pub extern "Rust" fn ddog_php_prof_add_endpoint(
    profile: &mut Profile,
    local_root_span_id: u64,
    endpoint: &str,
) -> anyhow::Result<()> {
    profile
        .inner
        .add_endpoint(local_root_span_id, Cow::Borrowed(endpoint))
}

#[no_mangle]
pub extern "Rust" fn ddog_php_prof_add_endpoint_count(
    profile: &mut Profile,
    endpoint: &str,
    count: i64,
) -> anyhow::Result<()> {
    profile
        .inner
        .add_endpoint_count(Cow::Borrowed(endpoint), count)
}

/// # Safety
/// Called only from Profile::drop, exactly once with a live inner value. The
/// wrapper must not be used or moved afterward, including on unwind.
#[no_mangle]
pub unsafe extern "Rust" fn ddog_php_prof_drop(profile: &mut Profile) {
    #[cfg(test)]
    RELEASES.with(|counts| counts.set((counts.get().0 + 1, counts.get().1)));
    // SAFETY: the caller is destroying the wrapper, whose field will not be
    // automatically dropped. Do not drop the wrapper itself: that would recurse.
    unsafe { ManuallyDrop::drop(&mut profile.inner) }
}

/// Move the inner profile to a consuming operation, without running the
/// forwarding destructor. All subsequent cleanup is local to this runtime.
fn into_inner(profile: Profile) -> InternalProfile {
    let mut profile = ManuallyDrop::new(profile);
    #[cfg(test)]
    RELEASES.with(|counts| counts.set((counts.get().0, counts.get().1 + 1)));
    // SAFETY: the wrapper is owned, its inner value is live, and the outer
    // ManuallyDrop suppresses the forwarding destructor after this move.
    unsafe { ManuallyDrop::take(&mut profile.inner) }
}

// Test-only accounting distinguishes forwarded drops from consuming transfers.
// Thread-local counters keep parallel unit tests independent.
#[cfg(test)]
thread_local! {
    pub(super) static RELEASES: std::cell::Cell<(usize, usize)> = const {
        std::cell::Cell::new((0, 0))
    };
}

/// This is a Rust ABI, despite its unmangled name. It is only called by the
/// matching extension build, never by C or a separately versioned library.
#[no_mangle]
pub extern "Rust" fn ddog_php_prof_serialize_and_upload(upload: Upload) -> anyhow::Result<u16> {
    let endpoint = match upload.endpoint {
        AgentEndpoint::Uri(uri) => config::agent(uri.parse()?),
        #[cfg(unix)]
        AgentEndpoint::Socket(path) => config::agent_uds(&path),
        #[cfg(not(unix))]
        AgentEndpoint::Socket(_) => anyhow::bail!("Unix profile sockets are not supported"),
    }?
    .with_timeout(10_000);

    // Preserve the per-upload exporter lifetime and its uploader-thread affinity.
    // In particular, no exporter/runtime is retained across the fork barrier.
    let mut exporter = ProfileExporter::new(
        upload.library_name,
        upload.library_version,
        "php",
        upload.tags,
        endpoint,
    )?;
    let serialized = into_inner(upload.profile)
        .serialize_into_compressed_pprof(Some(upload.end_time), upload.duration)?;
    Ok(exporter
        .send_blocking(
            serialized,
            &[],
            &[],
            upload.internal_metadata,
            upload.info,
            upload.process_tags.as_deref(),
            None,
        )?
        .as_u16())
}

#[no_mangle]
pub extern "Rust" fn ddog_php_prof_serialize(profile: Profile) -> anyhow::Result<Vec<u8>> {
    Ok(into_inner(profile)
        .serialize_into_compressed_pprof(None, None)?
        .buffer)
}
