// PHP-version-independent tracer implementation. SSI builds this module into
// libdatadog_php.so; non-SSI links it into ddtrace.so. The PHP-specific C tracer
// is compiled separately in both modes.

#[cfg(feature = "tracer-runtime")]
pub mod agent_info;
#[cfg(feature = "tracer-runtime")]
pub mod bytes;
#[cfg(feature = "tracer-runtime")]
pub mod ffe;
#[cfg(feature = "tracer-runtime")]
pub mod remote_config;
#[cfg(feature = "sidecar")]
pub mod sidecar;
#[cfg(all(feature = "sidecar", target_os = "linux"))]
pub mod signal_flush;
#[cfg(feature = "tracer-runtime")]
pub mod stats;
#[cfg(feature = "tracer-runtime")]
pub mod telemetry;
#[cfg(feature = "tracer-runtime")]
pub mod trace_filter;
