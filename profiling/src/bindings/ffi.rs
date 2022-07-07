// Of course, the C API doesn't follow Rust conventions, so suppress lints.
#![allow(clippy::all)]
#![allow(warnings)]

use crate::bindings::{
    _zend_module_entry, zend_bool, zend_extension, zend_module_entry, zend_result,
};

include!(concat!(env!("OUT_DIR"), "/bindings.rs"));
