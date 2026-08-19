//! Facade for the common Rust components.

#[cfg(not(standalone_profiler))]
pub use crate::{
    agent_info, bytes, ffe, log, remote_config, sidecar, stats, telemetry, trace_filter,
};
