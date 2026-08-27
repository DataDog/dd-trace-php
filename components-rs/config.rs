// This was generated too, ignore it. Existing profiler bindgen can be used.

//! Access to ZAI config values (the `DD_*`/`datadog.*` runtime configuration
//! declared via the `CONFIG`/`CALIAS` macro tables in `ext/configuration.h`
//! and `tracer/configuration.h`) from Rust.
//!
//! The `Zval`/`ZaiConfigMemoizedEntry` layouts below mirror Zend's/ZAI's real
//! C structs (`Zend/zend_types.h`'s `zval`,
//! `zend_abstract_interface/config/config.h`'s `zai_config_memoized_entry_s`)
//! byte-for-byte, the same way `bytes::ZendString` already mirrors
//! `zend_string`. `zai_config_get_value`/`zai_config_memoized_entries` are
//! non-static ZAI symbols linked into the same `ddtrace.so`, so they're
//! called directly via `extern "C"` rather than through a C shim.
//!
//! The per-config `ConfigId` enum and `get_<NAME>()`/`get_global_<NAME>()`
//! accessors below are generated at build time (see
//! `components-rs/config_codegen.rs`) straight from the same macro tables,
//! so they can never drift from the real config list.

use crate::bytes::{u8_from_zend_string, ZendString};
use std::ffi::c_void;

const ZAI_CONFIG_NAMES_COUNT_MAX: usize = 4;
const ZAI_CONFIG_NAME_BUFSIZ: usize = 72;

/// Mirrors Zend's `zend_value` union (`Zend/zend_types.h`). Only the members
/// this module reads are declared; the rest are equally-sized/aligned with
/// the pointer/`ptr` member so the union's overall size (8 bytes) matches.
#[repr(C)]
pub union ZvalValue {
    pub lval: i64,
    pub dval: f64,
    pub str_: *mut ZendString,
    pub arr: *mut c_void,
    pub ptr: *mut c_void,
}

/// Mirrors Zend's `zval` (`struct _zval_struct`): the 8-byte `value` union,
/// followed by the 4-byte `u1` union (read here only as its `type_info`
/// member) and the 4-byte `u2` union (never read, kept only so this struct's
/// size matches `sizeof(zval)` for the sake of `ZaiConfigMemoizedEntry`'s
/// layout below).
#[repr(C)]
pub struct Zval {
    pub value: ZvalValue,
    pub type_info: u32,
    _u2: u32,
}

const IS_TRUE: u8 = 3;

/// The low byte of `type_info` is Zend's `zval.u1.v.type` on little-endian
/// targets (the only endianness ddtrace builds for).
unsafe fn zval_type(v: *mut Zval) -> u8 {
    (*v).type_info as u8
}

pub unsafe fn zval_is_true(v: *mut Zval) -> bool {
    zval_type(v) == IS_TRUE
}

pub unsafe fn zval_lval(v: *mut Zval) -> i64 {
    (*v).value.lval
}

pub unsafe fn zval_dval(v: *mut Zval) -> f64 {
    (*v).value.dval
}

pub unsafe fn zval_string(v: *mut Zval) -> String {
    String::from_utf8_lossy(u8_from_zend_string(&*(*v).value.str_)).into_owned()
}

pub unsafe fn zval_arr(v: *mut Zval) -> *mut c_void {
    (*v).value.arr
}

/// Mirrors `zai_config_name_s` (`zend_abstract_interface/config/config.h`).
#[repr(C)]
struct ZaiConfigName {
    _len: usize,
    _ptr: [u8; ZAI_CONFIG_NAME_BUFSIZ],
}

/// Mirrors `zai_str_s` (`zend_abstract_interface/zai_string/string.h`).
#[repr(C)]
struct ZaiStr {
    _ptr: *const u8,
    _len: usize,
}

/// Mirrors `zai_config_memoized_entry_s`
/// (`zend_abstract_interface/config/config.h`). Every field is kept, in
/// declaration order, so `decoded_value`'s offset matches the real struct;
/// fields this module never reads are given ABI-equivalent (same
/// size/alignment) stand-in types instead of their real named types.
#[repr(C)]
struct ZaiConfigMemoizedEntry {
    _names: [ZaiConfigName; ZAI_CONFIG_NAMES_COUNT_MAX],
    _ini_entries: [*mut c_void; ZAI_CONFIG_NAMES_COUNT_MAX],
    _names_count: u8,
    _type: u32,
    decoded_value: Zval,
    _default_encoded_value: ZaiStr,
    _name_index: i16,
    _config_id: ZaiStr,
    _ini_change: *mut c_void,
    _parser: *mut c_void,
    _displayer: *mut c_void,
    _env_config_fallback: *mut c_void,
    _original_on_modify: *mut c_void,
}

const ZAI_CONFIG_ENTRIES_COUNT_MAX: usize = 320;

extern "C" {
    fn zai_config_get_value(id: u16) -> *mut Zval;
    static mut zai_config_memoized_entries: [ZaiConfigMemoizedEntry; ZAI_CONFIG_ENTRIES_COUNT_MAX];
}

pub unsafe fn config_value(id: u16) -> *mut Zval {
    zai_config_get_value(id)
}

pub unsafe fn config_global_value(id: u16) -> *mut Zval {
    std::ptr::addr_of_mut!(zai_config_memoized_entries[id as usize].decoded_value)
}

include!(concat!(env!("OUT_DIR"), "/generated_config.rs"));
