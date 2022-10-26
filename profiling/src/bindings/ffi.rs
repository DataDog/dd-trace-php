// Of course, the C API doesn't follow Rust conventions, so suppress lints.
#![allow(clippy::all)]
#![allow(warnings)]

use crate::bindings::{
    _zend_module_entry, zend_bool, zend_extension, zend_module_entry, zend_result, ZaiConfigEntry,
    ZaiConfigMemoizedEntry, ZaiStringView, ZendString,
};

pub type _zend_string = ZendString;

pub type zai_string_view_s<'a> = ZaiStringView<'a>;
pub type zai_string_view<'a> = zai_string_view_s<'a>;

pub type zai_config_entry_s = ZaiConfigEntry;
pub type zai_config_memoized_entry_s = ZaiConfigMemoizedEntry;

include!(concat!(env!("OUT_DIR"), "/bindings.rs"));
