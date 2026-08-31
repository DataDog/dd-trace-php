//! Rust IDs for the aggregate common/product ZAI configuration table.
//!
//! IDs and storage-typed accessors are generated from the shared X-macro
//! declarations. Product-specific validation and composed defaults remain in
//! the product modules. Logical representations, including the unique-key
//! `DD_TAGS` map, are shared without mirroring Zend/ZAI layouts here.

include!(concat!(env!("OUT_DIR"), "/generated_config.rs"));
