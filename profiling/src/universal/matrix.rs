//! Offset matrix for universal PHP version support.
//! Entries are keyed by (api_no, build_id, zts). Offsets use isize; -1 for not applicable.
//! Platform-specific at compile time: one static per (target_os, target_arch).

/// Sentinel for offsets that are not applicable in a given PHP version.
pub const PROBE_OFF_NA: isize = -1;

#[derive(Debug, Clone)]
pub struct MatrixKey {
    pub api_no: i32,
    pub build_id: &'static str,
    pub arch: &'static str,
    pub zts: bool,
    pub debug: bool,
}

/// Offsets into PHP structs. All fields named; no HashMap lookup overhead.
/// Use PROBE_OFF_NA (-1) for not applicable fields.
#[derive(Debug, Clone)]
pub struct MatrixOffsets {
    pub eg_current_execute_data: isize,
    pub eg_vm_interrupt: isize,
    pub ex_opline: isize,
    pub ex_func: isize,
    pub ex_prev_execute_data: isize,
    pub func_common_type: isize,
    pub func_common_fn_flags: isize,
    pub func_common_function_name: isize,
    pub func_common_scope: isize,
    pub func_internal_module: isize,
    pub func_internal_reserved: isize,
    pub func_op_array_filename: isize,
    pub func_op_array_reserved: isize,
    pub func_op_array_opcodes: isize,
    pub func_op_array_last: isize,
    pub func_op_array_run_time_cache: isize,
    pub func_common_run_time_cache: isize,
    pub func_internal_handler: isize,
    pub module_name: isize,
    pub class_name: isize,
    pub zend_string_len: isize,
    pub zend_string_val: isize,
    pub op_lineno: isize,
    pub op_opcode: isize,
    pub op_extended_value: isize,
    pub cg_function_table: isize,
    pub cg_class_table: isize,
    pub ce_function_table: isize,
    pub eg_active_fiber: isize,
    pub zend_extension_handle: isize,
    pub zend_module_entry_build_id: isize,
    /// Offset of request_info within sapi_globals_struct (after server_context).
    pub sg_request_info: isize,
    /// Offset of argc within sapi_request_info.
    pub sapi_request_info_argc: isize,
    /// Offset of argv within sapi_request_info.
    pub sapi_request_info_argv: isize,
}

/// Constants for this PHP version. All fields named; no HashMap lookup overhead.
#[derive(Debug, Clone)]
pub struct MatrixConstants {
    pub func_type_internal: u32,
    pub trampoline_flag_mask: u32,
    pub call_trampoline_opcode: u32,
    pub frameless_icall_0_opcode: u32,
    pub frameless_icall_3_opcode: u32,
}

/// Pre-computed feature flags for this PHP version. Derived from api_no at table-build time so
/// callers pay only a single bool load, not a range comparison on every call.
#[derive(Debug, Clone)]
pub struct MatrixFeatures {
    /// zend_mm_set_custom_handlers_ex is available (PHP 8.4+).
    pub zend_mm_custom_handlers_ex: bool,
    /// zend_accel_schedule_restart_hook is available (PHP 8.4+).
    pub opcache_restart_hook: bool,
    /// zend_gc_get_status is available (PHP 7.4+).
    pub gc_status: bool,
    /// eg.active_fiber / fiber support is present (PHP 8.1+).
    pub fibers: bool,
    /// zend_observer_error_register is available (PHP 8.0+).
    pub zend_error_observer: bool,
    /// Error observer callback takes `const char*` for the filename (PHP 8.0–8.1).
    /// On PHP 8.2+ the parameter is `zend_string*` instead.
    pub zend_error_observer_cstr_filename: bool,
}

impl MatrixFeatures {
    pub const fn from_api_no(api_no: i32) -> Self {
        Self {
            zend_mm_custom_handlers_ex: api_no >= 420240924,
            opcache_restart_hook: api_no >= 420240924,
            gc_status: api_no >= 320190902,
            fibers: api_no >= 420210902,
            zend_error_observer: api_no >= 420200930,
            zend_error_observer_cstr_filename: api_no >= 420200930 && api_no < 420220829,
        }
    }
}

#[derive(Debug, Clone)]
pub struct MatrixEntry {
    pub key: MatrixKey,
    pub globals_mode: &'static str,
    pub offsets: MatrixOffsets,
    pub constants: MatrixConstants,
    pub features: MatrixFeatures,
}

impl MatrixEntry {
    /// ZEND_ACC_CALL_VIA_TRAMPOLINE mask for this PHP version.
    /// PHP 7.1–7.3: 0x200000 (2097152); PHP 7.4+: 1<<18 (262144).
    pub fn trampoline_flag_mask(&self) -> u32 {
        self.constants.trampoline_flag_mask
    }

    /// True if this PHP version has frameless opcodes (PHP 8.4+).
    pub fn has_frameless(&self) -> bool {
        self.constants.frameless_icall_0_opcode != 0
    }

    /// Frameless ICALL opcodes for this PHP version. Only valid when has_frameless().
    /// Returns (icall_0, icall_1, icall_2, icall_3).
    pub fn frameless_icall_opcodes(&self) -> (u32, u32, u32, u32) {
        let f0 = self.constants.frameless_icall_0_opcode;
        let f3 = self.constants.frameless_icall_3_opcode;
        (f0, f0 + 1, f0 + 2, f3)
    }

    /// True if this PHP version has op_array run_time_cache (PHP 8.4+).
    pub fn has_run_time_cache(&self) -> bool {
        self.offsets.func_op_array_run_time_cache >= 0
    }

    /// True if PHP was built with ZTS (thread safety).
    pub fn is_zts(&self) -> bool {
        self.key.zts
    }

    /// True if zend_mm_set_custom_handlers_ex is available (PHP 8.4+).
    pub fn has_zend_mm_set_custom_handlers_ex(&self) -> bool {
        self.features.zend_mm_custom_handlers_ex
    }

    /// True if zend_accel_schedule_restart_hook is available (PHP 8.4+).
    pub fn has_opcache_restart_hook(&self) -> bool {
        self.features.opcache_restart_hook
    }

    /// True if zend_gc_get_status is available (PHP 7.4+).
    pub fn has_gc_status(&self) -> bool {
        self.features.gc_status
    }

    /// True if fibers are supported — eg.active_fiber is present (PHP 8.1+).
    pub fn has_fibers(&self) -> bool {
        self.features.fibers
    }

    /// True if zend_observer_error_register is available (PHP 8.0+).
    pub fn has_zend_error_observer(&self) -> bool {
        self.features.zend_error_observer
    }

    /// True when the error observer callback receives `const char*` for the filename
    /// (PHP 8.0–8.1). On PHP 8.2+ the parameter is `zend_string*` instead.
    pub fn zend_error_observer_has_cstr_filename(&self) -> bool {
        self.features.zend_error_observer_cstr_filename
    }
}

macro_rules! off {
    ($eg_cur:expr, $eg_int:expr, $mod_:expr, $int_res:expr, $filename:expr,
     $op_res:expr, $opcodes:expr, $last:expr, $rt_cache:expr, $rt_cache_common:expr,
     $handler:expr, $cg_ft:expr, $active_fiber:expr,
     $zext_handle:expr, $build_id_off:expr) => {
        MatrixOffsets {
            eg_current_execute_data: $eg_cur,
            eg_vm_interrupt: $eg_int,
            ex_opline: 0,
            ex_func: 24,
            ex_prev_execute_data: 48,
            func_common_type: 0,
            func_common_fn_flags: 4,
            func_common_function_name: 8,
            func_common_scope: 16,
            func_internal_module: $mod_,
            func_internal_reserved: $int_res,
            func_op_array_filename: $filename,
            func_op_array_reserved: $op_res,
            func_op_array_opcodes: $opcodes,
            func_op_array_last: $last,
            func_op_array_run_time_cache: $rt_cache,
            func_common_run_time_cache: $rt_cache_common,
            func_internal_handler: $handler,
            module_name: 32,
            class_name: 8,
            zend_string_len: 16,
            zend_string_val: 24,
            op_lineno: 24,
            op_opcode: 28,
            op_extended_value: 20,
            cg_function_table: $cg_ft,
            cg_class_table: if $cg_ft >= 0 {
                $cg_ft + 8
            } else {
                PROBE_OFF_NA
            },
            ce_function_table: 64,
            eg_active_fiber: $active_fiber,
            zend_extension_handle: $zext_handle,
            zend_module_entry_build_id: $build_id_off,
            sg_request_info: 8,
            sapi_request_info_argc: 128,
            sapi_request_info_argv: 136,
        }
    };
}

macro_rules! consts {
    ($trampoline:expr, $f0:expr, $f3:expr) => {
        MatrixConstants {
            func_type_internal: 1,
            trampoline_flag_mask: $trampoline,
            call_trampoline_opcode: 158,
            frameless_icall_0_opcode: $f0,
            frameless_icall_3_opcode: $f3,
        }
    };
}

macro_rules! entry {
    ($api_no:expr, $build_id:expr, $arch:expr, $zts:expr, $mode:expr, $offsets:expr, $constants:expr) => {
        MatrixEntry {
            key: MatrixKey {
                api_no: $api_no,
                build_id: $build_id,
                arch: $arch,
                zts: $zts,
                debug: false,
            },
            globals_mode: $mode,
            offsets: $offsets,
            constants: $constants,
            features: MatrixFeatures::from_api_no($api_no),
        }
    };
}

/// Platform-specific matrix. Compiled for single (target_os, target_arch); supports PHP 7.1–8.5.
#[cfg(all(target_os = "linux", target_arch = "x86_64"))]
static MATRIX_ENTRIES: &[MatrixEntry] = &[
    entry!(
        320160303,
        "API320160303,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            480,
            530,
            56,
            PROBE_OFF_NA,
            120,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA
        ),
        consts!(2097152, 0, 0)
    ),
    entry!(
        320160303,
        "API320160303,TS",
        "x86_64",
        true,
        "zts_id",
        off!(
            480,
            530,
            56,
            PROBE_OFF_NA,
            120,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA
        ),
        consts!(2097152, 0, 0)
    ),
    entry!(
        320170718,
        "API320170718,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            480,
            530,
            56,
            64,
            120,
            176,
            64,
            56,
            168,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320170718,
        "API320170718,TS",
        "x86_64",
        true,
        "zts_id",
        off!(
            480,
            530,
            56,
            64,
            120,
            176,
            64,
            56,
            168,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320180731,
        "API320180731,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            56,
            64,
            128,
            168,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320180731,
        "API320180731,TS",
        "x86_64",
        true,
        "zts_id",
        off!(
            488,
            546,
            56,
            64,
            128,
            168,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320190902,
        "API320190902,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320190902,
        "API320190902,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420240924,
        "API420240924,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420240924,
        "API420240924,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
];

#[cfg(all(target_os = "linux", target_arch = "aarch64"))]
static MATRIX_ENTRIES: &[MatrixEntry] = &[
    entry!(
        320160303,
        "API320160303,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            480,
            530,
            56,
            PROBE_OFF_NA,
            120,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA
        ),
        consts!(2097152, 0, 0)
    ),
    entry!(
        320160303,
        "API320160303,TS",
        "aarch64",
        true,
        "zts_id",
        off!(
            480,
            530,
            56,
            PROBE_OFF_NA,
            120,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA,
            PROBE_OFF_NA
        ),
        consts!(2097152, 0, 0)
    ),
    // PHP 7.2 aarch64 (offsets measured from datadog/dd-trace-ci:php-7.2_bookworm-6 nts/zts)
    entry!(
        320170718,
        "API320170718,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            480,
            530,
            56,
            64,
            120,
            176,
            64,
            56,
            168,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320170718,
        "API320170718,TS",
        "aarch64",
        true,
        "zts_id",
        off!(
            480,
            530,
            56,
            64,
            120,
            176,
            64,
            56,
            168,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    // PHP 7.3 aarch64 (offsets measured from datadog/dd-trace-ci:php-7.3_bookworm-6 nts/zts)
    entry!(
        320180731,
        "API320180731,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            56,
            64,
            128,
            168,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320180731,
        "API320180731,TS",
        "aarch64",
        true,
        "zts_id",
        off!(
            488,
            546,
            56,
            64,
            128,
            168,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320190902,
        "API320190902,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320190902,
        "API320190902,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420240924,
        "API420240924,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420240924,
        "API420240924,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
];

#[cfg(all(target_os = "macos", target_arch = "x86_64"))]
static MATRIX_ENTRIES: &[MatrixEntry] = &[
    entry!(
        320190902,
        "API320190902,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320190902,
        "API320190902,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420240924,
        "API420240924,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420240924,
        "API420240924,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,NTS",
        "x86_64",
        false,
        "nts_direct",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,TS",
        "x86_64",
        true,
        "zts_fast_offset",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
];

#[cfg(all(target_os = "macos", target_arch = "aarch64"))]
static MATRIX_ENTRIES: &[MatrixEntry] = &[
    entry!(
        320190902,
        "API320190902,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        320190902,
        "API320190902,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            56,
            64,
            136,
            176,
            64,
            60,
            72,
            PROBE_OFF_NA,
            48,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420200930,
        "API420200930,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            184,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            PROBE_OFF_NA,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420210902,
        "API420210902,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(
            488,
            546,
            64,
            72,
            144,
            192,
            72,
            68,
            80,
            PROBE_OFF_NA,
            56,
            56,
            1672,
            192,
            160
        ),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420220829,
        "API420220829,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(488, 546, 80, 88, 152, 200, 88, 80, 64, 64, 72, 56, 1680, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420230831,
        "API420230831,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(488, 534, 80, 88, 144, 192, 80, 76, 56, 56, 72, 56, 1664, 192, 160),
        consts!(262144, 0, 0)
    ),
    entry!(
        420240924,
        "API420240924,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420240924,
        "API420240924,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(488, 550, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1752, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,NTS",
        "aarch64",
        false,
        "nts_direct",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
    entry!(
        420250925,
        "API420250925,TS",
        "aarch64",
        true,
        "zts_fast_offset",
        off!(512, 574, 96, 112, 168, 208, 104, 96, 56, 56, 88, 56, 1776, 192, 160),
        consts!(262144, 204, 207)
    ),
];

#[cfg(not(any(
    all(target_os = "linux", target_arch = "x86_64"),
    all(target_os = "linux", target_arch = "aarch64"),
    all(target_os = "macos", target_arch = "x86_64"),
    all(target_os = "macos", target_arch = "aarch64"),
)))]
static MATRIX_ENTRIES: &[MatrixEntry] = &[];

/// Returns the first non-debug NTS entry in the matrix, or `None` on unsupported platforms.
/// Intended for use in unit tests that need a valid `MatrixEntry` without a live PHP process.
#[cfg(test)]
pub fn first_nts_entry() -> Option<&'static MatrixEntry> {
    MATRIX_ENTRIES.iter().find(|e| !e.key.zts && !e.key.debug)
}

/// Find matrix entry for the given (api_no, build_id, zts).
/// No arch filter needed—entries are compile-time filtered for current platform.
/// Debug builds are not supported; callers should reject them before calling this.
pub fn find_entry(
    api_no: i32,
    build_id: &str,
    zts: bool,
    debug: bool,
) -> Option<&'static MatrixEntry> {
    MATRIX_ENTRIES.iter().find(|e| {
        e.key.api_no == api_no
            && e.key.build_id == build_id
            && e.key.zts == zts
            && e.key.debug == debug
    })
}
