//! Rust facade for tracer support.
//!
//! The tracer's PHP-facing implementation remains in the repository's
//! `tracer/` source grouping. Common Rust exports continue to live in
//! [`crate::components`] so common-only builds retain their ABI.

pub use crate::{ffe, stats, trace_filter};
