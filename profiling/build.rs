//! Pure Rust build. No php-config, no C files, no PHP headers.
//! All PHP interaction via runtime symbol resolution and matrix tables.

use std::{env, fs};

fn main() {
    let version = fs::read_to_string("../VERSION")
        .expect("Failed to read VERSION file")
        .trim()
        .to_string();
    println!("cargo:rustc-env=PROFILER_VERSION={version}");
    println!("cargo:rerun-if-changed=../VERSION");

    emit_macos_undefined_symbols();
}

/// Emit link-args for PHP symbols we resolve at runtime via dlsym.
/// On macOS, the extension is a dylib loaded into PHP; these symbols
/// are resolved at load time from the PHP binary.
fn emit_macos_undefined_symbols() {
    let target = env::var("TARGET").unwrap();
    if !target.contains("apple-darwin") {
        return;
    }

    let symbols: &[&str] = &[
        "___zend_malloc",
        "__efree",
        "__emalloc",
        "__emalloc_56",
        "__emalloc_large",
        "__zend_handle_numeric_str_ex",
        "__zend_hash_init",
        "__zend_mm_alloc",
        "__zend_mm_free",
        "__zend_mm_realloc",
        "__zend_new_array_0",
        "_cfg_get_entry",
        "_compiler_globals",
        "_compiler_globals_offset",
        "_core_globals",
        "_display_ini_entries",
        "_execute_internal",
        "_executor_globals",
        "_gc_collect_cycles",
        "_instanceof_function_slow",
        "_is_zend_mm",
        "_module_registry",
        "_php_during_module_startup",
        "_php_get_module_initialized",
        "_php_info_print_table_end",
        "_php_info_print_table_row",
        "_php_info_print_table_start",
        "_php_version",
        "_php_version_id",
        "_rc_dtor_func",
        "_sapi_getenv",
        "_sapi_globals",
        "_sapi_globals_offset",
        "_sapi_module",
        "_tsrm_get_ls_cache",
        "_zend_alter_ini_entry_ex",
        "_zend_ce_throwable",
        "_zend_compile_file",
        "_zend_compile_string",
        "_zend_empty_string",
        "_zend_execute_internal",
        "_zend_extensions",
        "_zend_flf_functions",
        "_zend_gc_get_status",
        "_zend_get_executed_filename_ex",
        "_zend_get_executed_lineno",
        "_zend_get_extension",
        "_zend_get_internal_function_extension_handles",
        "_zend_get_op_array_extension_handles",
        "_zend_hash_add",
        "_zend_hash_add_empty_element",
        "_zend_hash_destroy",
        "_zend_hash_find",
        "_zend_hash_index_update",
        "_zend_hash_next_index_insert",
        "_zend_hash_str_add",
        "_zend_hash_str_find",
        "_zend_hash_update",
        "_zend_ini_boolean_displayer_cb",
        "_zend_ini_get_value",
        "_zend_ini_parse_bool",
        "_zend_ini_parse_quantity",
        "_zend_ini_string",
        "_zend_interned_strings_switch_storage",
        "_zend_interrupt_function",
        "_zend_known_strings",
        "_zend_mm_gc",
        "_zend_mm_get_custom_handlers",
        "_zend_mm_get_custom_handlers_ex",
        "_zend_mm_get_heap",
        "_zend_mm_set_custom_handlers",
        "_zend_mm_set_custom_handlers_ex",
        "_zend_mm_shutdown",
        "_zend_new_interned_string",
        "_zend_observer_error_register",
        "_zend_post_startup_cb",
        "_zend_register_extension",
        "_zend_register_ini_entries",
        "_zend_register_internal_module",
        "_zend_str_tolower",
        "_zend_string_init_interned",
        "_zend_strtod",
        "_zend_throw_exception_hook",
        "_zend_write",
        "_zval_internal_ptr_dtor",
        "_zval_ptr_dtor",
        "_ddtrace_runtime_id",
    ];

    for sym in symbols {
        println!("cargo:rustc-link-arg=-Wl,-U,{sym}");
    }
}
