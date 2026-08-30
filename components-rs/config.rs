//! Rust IDs for the aggregate common/product ZAI configuration table.
//!
//! Products keep their typed conversion helpers because validation, defaults,
//! and representation can be product-specific (for example, `DD_TAGS` is a
//! map in ddtrace and an encoded string in the standalone profiler table).
//! Generating only IDs and the table count avoids mirroring Zend/ZAI layouts
//! in this common Rust module.

include!(concat!(env!("OUT_DIR"), "/generated_config.rs"));
