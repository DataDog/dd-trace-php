use bindgen::callbacks::IntKind;
use std::collections::HashSet;
use std::path::Path;
use std::path::PathBuf;
use std::process::Command;
use std::{env, fs};

fn main() {
    let php_config_includes_output = Command::new("php-config")
        .arg("--includes")
        .output()
        .expect("Unable to run `php-config`. Is it in your PATH?");

    if !php_config_includes_output.status.success() {
        match String::from_utf8(php_config_includes_output.stderr) {
            Ok(stderr) => panic!("`php-config failed: {stderr}"),
            Err(err) => panic!(
                "`php-config` failed, not utf8: {}",
                String::from_utf8_lossy(err.as_bytes())
            ),
        }
    }

    // Read the version from the VERSION file
    let version = fs::read_to_string("../VERSION")
        .expect("Failed to read VERSION file")
        .trim()
        .to_string();
    println!("cargo:rustc-env=PROFILER_VERSION={version}");
    println!("cargo:rerun-if-changed=../VERSION");

    let php_config_includes = std::str::from_utf8(php_config_includes_output.stdout.as_slice())
        .expect("`php-config`'s stdout to be valid utf8");

    let vernum = php_config_vernum();
    let post_startup_cb = cfg_post_startup_cb(vernum);
    let preload = cfg_preload(vernum);
    let fibers = cfg_fibers(vernum);
    let run_time_cache = cfg_run_time_cache(vernum);
    let opcache_shm_cache = cfg_opcache_shm_cache(vernum);
    let trigger_time_sample = cfg_trigger_time_sample();
    let zend_error_observer = cfg_zend_error_observer(vernum);

    generate_bindings(php_config_includes, fibers, zend_error_observer);
    build_zend_php_ffis(
        php_config_includes,
        post_startup_cb,
        preload,
        run_time_cache,
        opcache_shm_cache,
        fibers,
        trigger_time_sample,
        zend_error_observer,
    );

    cfg_php_major_version(vernum);
    cfg_php_feature_flags(vernum);
    cfg_zts();
    cfg_apple_linker_flags();
}

fn php_config_vernum() -> u64 {
    let output = Command::new("php-config")
        .arg("--vernum")
        .output()
        .expect("Unable to run `php-config`. Is it in your PATH?");

    if !output.status.success() {
        match String::from_utf8(output.stderr) {
            Ok(stderr) => panic!("`php-config --vernum` failed: {stderr}"),
            Err(err) => panic!(
                "`php-config --vernum` failed, not utf8: {}",
                String::from_utf8_lossy(err.as_bytes())
            ),
        }
    }

    let vernum = std::str::from_utf8(output.stdout.as_slice())
        .expect("`php-config`'s stdout to be valid utf8");

    vernum
        .trim()
        .parse::<u64>()
        .expect("output to be a number and fit in u64")
}

const ZAI_H_FILES: &[&str] = &[
    "../zend_abstract_interface/zai_assert/zai_assert.h",
    "../zend_abstract_interface/zai_string/string.h",
    "../zend_abstract_interface/config/config.h",
    "../zend_abstract_interface/config/config_decode.h",
    "../zend_abstract_interface/config/config_ini.h",
    "../zend_abstract_interface/config/config_stable_file.h",
    "../zend_abstract_interface/env/env.h",
    "../zend_abstract_interface/exceptions/exceptions.h",
    "../zend_abstract_interface/json/json.h",
    "../components-rs/common.h",
    "../components-rs/library-config.h",
];

#[allow(clippy::too_many_arguments)]
fn build_zend_php_ffis(
    php_config_includes: &str,
    post_startup_cb: bool,
    preload: bool,
    run_time_cache: bool,
    opcache_shm_cache: bool,
    fibers: bool,
    trigger_time_sample: bool,
    zend_error_observer: bool,
) {
    println!("cargo:rerun-if-changed=src/php_ffi.h");
    println!("cargo:rerun-if-changed=src/php_ffi.c");
    println!("cargo:rerun-if-changed=../ext/handlers_api.c");
    println!("cargo:rerun-if-changed=../ext/handlers_api.h");

    // Profiling only needs config, exceptions and its dependencies.
    let zai_c_files = [
        "../zend_abstract_interface/config/config_decode.c",
        "../zend_abstract_interface/config/config_ini.c",
        "../zend_abstract_interface/config/config_stable_file.c",
        "../zend_abstract_interface/config/config.c",
        "../zend_abstract_interface/config/config_runtime.c",
        "../zend_abstract_interface/env/env.c",
        "../zend_abstract_interface/exceptions/exceptions.c",
        "../zend_abstract_interface/json/json.c",
        "../zend_abstract_interface/zai_string/string.c",
    ];

    for file in zai_c_files.iter().chain(ZAI_H_FILES.iter()) {
        println!("cargo:rerun-if-changed={file}");
    }

    let output = Command::new("php-config")
        .arg("--prefix")
        .output()
        .expect("Unable to run `php-config`. Is it in your PATH?");

    let prefix = String::from_utf8(output.stdout).expect("only utf8 chars work");
    println!(
        "cargo:rustc-link-search=native={prefix}/lib",
        prefix = prefix.trim()
    );

    let files = ["src/php_ffi.c", "../ext/handlers_api.c"];
    let post_startup_cb = if post_startup_cb { "1" } else { "0" };
    let preload = if preload { "1" } else { "0" };
    let fibers = if fibers { "1" } else { "0" };
    let run_time_cache = if run_time_cache { "1" } else { "0" };
    let opcache_shm_cache = if opcache_shm_cache { "1" } else { "0" };
    let trigger_time_sample = if trigger_time_sample { "1" } else { "0" };
    let zend_error_observer = if zend_error_observer { "1" } else { "0" };

    #[cfg(feature = "stack_walking_tests")]
    let stack_walking_tests = "1";

    #[cfg(not(feature = "stack_walking_tests"))]
    let stack_walking_tests = "0";

    let mut build = cc::Build::new();
    build
        .files(files.into_iter().chain(zai_c_files))
        .define("CFG_POST_STARTUP_CB", post_startup_cb)
        .define("CFG_PRELOAD", preload)
        .define("CFG_FIBERS", fibers)
        .define("CFG_RUN_TIME_CACHE", run_time_cache)
        .define("CFG_OPCACHE_SHM_CACHE", opcache_shm_cache)
        .define("CFG_STACK_WALKING_TESTS", stack_walking_tests)
        .define("CFG_TRIGGER_TIME_SAMPLE", trigger_time_sample)
        .define("CFG_ZEND_ERROR_OBSERVER", zend_error_observer)
        .includes([Path::new("../ext")])
        .includes(
            str::replace(php_config_includes, "-I", "")
                .split(' ')
                .map(Path::new)
                .chain([Path::new("../zend_abstract_interface")])
                .chain([Path::new("../")]),
        )
        .flag_if_supported("-fuse-ld=lld")
        .flag_if_supported("-std=c11")
        .flag_if_supported("-std=c17");
    #[cfg(feature = "test")]
    build.define("CFG_TEST", "1");
    build.compile("php_ffi");
}

#[derive(Debug)]
struct IgnoreMacros(HashSet<String>);

impl bindgen::callbacks::ParseCallbacks for IgnoreMacros {
    fn will_parse_macro(&self, name: &str) -> bindgen::callbacks::MacroParsingBehavior {
        if self.0.contains(name) {
            bindgen::callbacks::MacroParsingBehavior::Ignore
        } else {
            bindgen::callbacks::MacroParsingBehavior::Default
        }
    }

    fn int_macro(&self, name: &str, _value: i64) -> Option<IntKind> {
        match name {
            "IS_UNDEF" | "IS_NULL" | "IS_FALSE" | "IS_TRUE" | "IS_LONG" | "IS_DOUBLE"
            | "IS_STRING" | "IS_ARRAY" | "IS_OBJECT" | "IS_RESOURCE" | "IS_REFERENCE"
            | "_IS_BOOL" => Some(IntKind::U8),

            "ZEND_INTERNAL_FUNCTION" | "ZEND_USER_FUNCTION" => Some(IntKind::U8),

            // None means whatever it would have been without this hook
            // (likely u32).
            _ => None,
        }
    }
}

fn generate_bindings(php_config_includes: &str, fibers: bool, zend_error_observer: bool) {
    println!("cargo:rerun-if-changed=src/php_ffi.h");
    println!("cargo:rerun-if-changed=../ext/handlers_api.h");
    let ignored_macros = IgnoreMacros(
        [
            "FP_INFINITE".into(),
            "FP_NAN".into(),
            "FP_NORMAL".into(),
            "FP_SUBNORMAL".into(),
            "FP_ZERO".into(),
            "IPPORT_RESERVED".into(),
        ]
        .into_iter()
        .collect(),
    );

    let mut clang_args = if fibers {
        vec!["-D", "CFG_FIBERS=1"]
    } else {
        vec!["-D", "CFG_FIBERS=0"]
    };

    if zend_error_observer {
        clang_args.push("-D");
        clang_args.push("CFG_ZEND_ERROR_OBSERVER=1");
    } else {
        clang_args.push("-D");
        clang_args.push("CFG_ZEND_ERROR_OBSERVER=0");
    }

    let bindings = bindgen::Builder::default()
        .ctypes_prefix("libc")
        .clang_args(clang_args)
        .raw_line("extern crate libc;")
        .header("src/php_ffi.h")
        .header("../ext/handlers_api.h")
        .clang_arg("-I../zend_abstract_interface")
        .clang_arg("-I../")
        // Block some zend items that we'll provide manual definitions for
        .blocklist_item("zai_str_s")
        .blocklist_item("zai_str")
        .blocklist_item("zai_config_entry_s")
        .blocklist_item("zai_config_memoized_entry_s")
        .blocklist_item("zend_bool")
        .blocklist_item("_zend_extension")
        .blocklist_item("zend_extension")
        .blocklist_item("_zend_module_entry")
        .blocklist_item("zend_module_entry")
        .blocklist_item("zend_result")
        .blocklist_item("zend_register_extension")
        .blocklist_item("_zend_string")
        // Block typedefs that use __attribute__((preserve_none)) calling convention.
        // PHP 8.5.1+ on macOS enables TAILCALL VM when compiled with Clang 18+,
        // which uses preserve_none for opcode handlers. Bindgen doesn't support
        // CXCallingConv_PreserveNone (CC 20) and panics. We use opaque pointers
        // instead of the actual function signatures because: 1) we never call
        // these opcode handlers from Rust, and 2) Rust cannot express the
        // preserve_none calling convention anyway.
        .blocklist_item("zend_vm_opcode_handler_t")
        .blocklist_item("zend_vm_opcode_handler_func_t")
        .raw_line("pub type zend_vm_opcode_handler_t = *const ::std::ffi::c_void;")
        .raw_line("pub type zend_vm_opcode_handler_func_t = *const ::std::ffi::c_void;")
        // Block a few of functions that we'll provide defs for manually
        .blocklist_item("datadog_php_profiling_vm_interrupt_addr")
        // I had to block these for some reason *shrug*
        .blocklist_item("FP_INFINITE")
        .blocklist_item("FP_INT_DOWNWARD")
        .blocklist_item("FP_INT_TONEAREST")
        .blocklist_item("FP_INT_TONEARESTFROMZERO")
        .blocklist_item("FP_INT_TOWARDZERO")
        .blocklist_item("FP_INT_UPWARD")
        .blocklist_item("FP_NAN")
        .blocklist_item("FP_NORMAL")
        .blocklist_item("FP_SUBNORMAL")
        .blocklist_item("FP_ZERO")
        .blocklist_item("IPPORT_RESERVED")
        .rustified_enum("datadog_php_profiling_log_level")
        .rustified_enum("zai_config_type")
        .parse_callbacks(Box::new(ignored_macros))
        .clang_args(php_config_includes.split(' '))
        .parse_callbacks(Box::new(bindgen::CargoCallbacks::new()))
        .layout_tests(false)
        // this prevents bindgen from copying C comments to Rust, as otherwise
        // rustdoc would look for tests and currently fail as it assumes
        // codeblocks are Rust code
        .generate_comments(false)
        .generate()
        .expect("bindgen to succeed");

    let out_path = PathBuf::from(env::var("OUT_DIR").unwrap());
    bindings
        .write_to_file(out_path.join("bindings.rs"))
        .expect("bindings to be written successfully");
}

fn cfg_post_startup_cb(vernum: u64) -> bool {
    println!("cargo::rustc-check-cfg=cfg(php_post_startup_cb)");
    if vernum >= 70300 {
        println!("cargo:rustc-cfg=php_post_startup_cb");
        true
    } else {
        false
    }
}

fn cfg_preload(vernum: u64) -> bool {
    println!("cargo::rustc-check-cfg=cfg(php_preload)");
    if vernum >= 70400 {
        println!("cargo:rustc-cfg=php_preload");
        true
    } else {
        false
    }
}

fn cfg_run_time_cache(vernum: u64) -> bool {
    println!("cargo::rustc-check-cfg=cfg(php_run_time_cache)");
    if vernum >= 80000 {
        println!("cargo:rustc-cfg=php_run_time_cache");
        true
    } else {
        false
    }
}

fn cfg_opcache_shm_cache(vernum: u64) -> bool {
    println!("cargo::rustc-check-cfg=cfg(php_opcache_shm_cache)");
    // Gated to PHP 8.0+ because zend_get_resource_handle only contributes to
    // zend_system_id (used by opcache for cache invalidation) from PHP 8.0
    // onward. On PHP 7.x, changes in handle assignments across runs would not
    // invalidate the cache, potentially leading to stale reserved[] pointers.
    if vernum >= 80000 {
        println!("cargo:rustc-cfg=php_opcache_shm_cache");
        true
    } else {
        false
    }
}

fn cfg_trigger_time_sample() -> bool {
    env::var("CARGO_FEATURE_TRIGGER_TIME_SAMPLE").is_ok()
}

fn cfg_zend_error_observer(vernum: u64) -> bool {
    println!("cargo::rustc-check-cfg=cfg(zend_error_observer, zend_error_observer_80)");
    if vernum >= 80000 {
        println!("cargo:rustc-cfg=zend_error_observer");
        if vernum < 80100 {
            println!("cargo:rustc-cfg=zend_error_observer_80");
        }
        true
    } else {
        false
    }
}

fn cfg_php_major_version(vernum: u64) {
    println!("cargo::rustc-check-cfg=cfg(php7, php8)");

    let major_version = match vernum {
        70000..=79999 => 7,
        80000..=89999 => 8,
        _ => panic!("Unsupported or unrecognized major PHP version number: {vernum}"),
    };

    // Note that this is a "bad use" of cfg in Rust. Configurations are meant
    // to be additive, but these are exclusive. At the time of writing, this
    // was best way I could think of to address php 7 vs php 8 code paths
    // despite this misuse of the feature.
    println!("cargo:rustc-cfg=php{major_version}");
}

fn cfg_fibers(vernum: u64) -> bool {
    println!("cargo::rustc-check-cfg=cfg(php_has_fibers)");
    if vernum >= 80100 {
        println!("cargo:rustc-cfg=php_has_fibers");
        true
    } else {
        false
    }
}

fn cfg_php_feature_flags(vernum: u64) {
    println!("cargo::rustc-check-cfg=cfg(php_gc_status, php_zend_compile_string_has_position, php_gc_status_extended, php_frameless, php_opcache_restart_hook, php_zend_mm_set_custom_handlers_ex)");

    if vernum >= 70300 {
        println!("cargo:rustc-cfg=php_gc_status");
    }
    if vernum >= 80200 {
        println!("cargo:rustc-cfg=php_zend_compile_string_has_position");
    }
    if vernum >= 80300 {
        println!("cargo:rustc-cfg=php_gc_status_extended");
    }
    if vernum >= 80400 {
        println!("cargo:rustc-cfg=php_frameless");
        println!("cargo:rustc-cfg=php_opcache_restart_hook");
        println!("cargo:rustc-cfg=php_zend_mm_set_custom_handlers_ex");
    }
}

fn cfg_zts() {
    println!("cargo::rustc-check-cfg=cfg(php_zts)");

    let output = Command::new("php-config")
        .arg("--include-dir")
        .output()
        .expect("Unable to run `php-config`. Is it in your PATH?");

    if !output.status.success() {
        match String::from_utf8(output.stderr) {
            Ok(stderr) => panic!("`php-config --include-dir` failed: {stderr}"),
            Err(err) => panic!("`php-config --include-dir` failed, not utf8: {err}"),
        }
    }

    let include_dir = std::str::from_utf8(output.stdout.as_slice())
        .expect("`php-config`'s stdout to be valid utf8")
        .trim();

    // Create a temporary C file to probe ZTS
    let out_dir = env::var("OUT_DIR").unwrap();
    let probe_path = Path::new(&out_dir).join("zts_probe.c");
    fs::write(
        &probe_path,
        r#"
#include "main/php_config.h"
#include <stdio.h>
int main() {
#ifdef ZTS
    printf("1");
#else
    printf("0");
#endif
    return 0;
}
"#,
    )
    .expect("Failed to write ZTS probe file");

    // Get the C compiler from cc crate
    let compiler = cc::Build::new().get_compiler();

    // Compile the probe to an executable
    let probe_exe = Path::new(&out_dir).join("zts_probe");
    let compile_status = Command::new(compiler.path())
        .arg(format!("-I{}", include_dir))
        .arg(&probe_path)
        .arg("-o")
        .arg(&probe_exe)
        .status()
        .expect("Failed to compile ZTS probe");

    if !compile_status.success() {
        panic!("Failed to compile ZTS probe");
    }

    // Run the probe
    let probe_output = Command::new(&probe_exe)
        .output()
        .expect("Failed to run ZTS probe");

    let zts_value = std::str::from_utf8(&probe_output.stdout)
        .expect("ZTS probe output not UTF-8")
        .trim();

    if zts_value == "1" {
        println!("cargo:rustc-cfg=php_zts");
    }
}

/// On macOS (Apple targets), the cdylib has undefined symbols that are
/// resolved at load time by the PHP process. In release builds, we use
/// explicit `-Wl,-U,_symbol` flags so that `no_panic`'s sentinel symbols
/// cause a linker error if a panic path survives optimization. In debug
/// builds (where `no_panic` is inactive and LTO is off, producing more
/// unresolved symbols), we fall back to `-undefined dynamic_lookup`.
///
/// To regenerate the symbol list after adding new PHP/C API calls:
///   1. Build a release: `cargo build -p datadog-php-profiling --release`
///      (with `-undefined dynamic_lookup` temporarily enabled)
///   2. Extract symbols:
///      `nm -u target/release/libdatadog_php_profiling.dylib | sort -u |
///       grep -v '^$\|^ERROR\|dyld_stub' | sed 's/^/-Wl,-U,/' | tr '\n' ' '`
///   3. Update the ALLOWED_UNDEFINED_SYMBOLS list below.
fn cfg_apple_linker_flags() {
    let target = env::var("TARGET").unwrap_or_default();
    if !target.contains("apple") {
        return;
    }

    let profile = env::var("PROFILE").unwrap_or_default();
    if profile != "release" {
        // Debug builds: allow all undefined symbols.
        println!("cargo:rustc-cdylib-link-arg=-undefined");
        println!("cargo:rustc-cdylib-link-arg=dynamic_lookup");
        return;
    }

    // Release builds: explicitly allow only PHP/Zend symbols that are
    // resolved at load time from the PHP binary. System symbols (libc,
    // libSystem, CoreFoundation, Security, etc.) are resolved by the
    // linker from their respective libraries/frameworks. This way, any
    // NEW undefined symbol — such as a no_panic sentinel — causes a
    // linker error.
    //
    // Note: on macOS, symbols get a leading underscore, so `_efree` in C
    // becomes `__efree` here, and `__zend_malloc` becomes `___zend_malloc`.
    const ALLOWED_UNDEFINED_SYMBOLS: &[&str] = &[
        // Zend memory allocator
        "___zend_malloc",
        "__efree",
        "__emalloc",
        "__emalloc_40",
        "__emalloc_48",
        "__emalloc_56",
        "__emalloc_large",
        "__zend_handle_numeric_str_ex",
        "__zend_hash_init",
        "__zend_mm_alloc",
        "__zend_mm_free",
        "__zend_mm_realloc",
        // Zend globals
        "_compiler_globals",
        "_core_globals",
        "_executor_globals",
        "_module_registry",
        // Zend engine APIs
        "_cfg_get_entry",
        "_display_ini_entries",
        "_gc_collect_cycles",
        "_instanceof_function_slow",
        "_is_zend_mm",
        "_rc_dtor_func",
        "_zend_accel_schedule_restart_hook",
        "_zend_alter_ini_entry_ex",
        "_zend_ce_throwable",
        "_zend_compile_file",
        "_zend_compile_string",
        "_zend_empty_string",
        "_zend_extensions",
        "_zend_flf_functions",
        "_zend_gc_get_status",
        "_zend_get_executed_filename_ex",
        "_zend_get_executed_lineno",
        "_zend_get_extension",
        "_zend_get_internal_function_extension_handles",
        "_zend_get_op_array_extension_handles",
        "_zend_get_resource_handle",
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
        "_zend_mm_get_custom_handlers_ex",
        "_zend_mm_get_heap",
        "_zend_mm_set_custom_handlers_ex",
        "_zend_mm_shutdown",
        "_zend_new_interned_string",
        "_zend_observer_error_register",
        "_zend_post_startup_cb",
        "_zend_register_extension",
        "_zend_register_ini_entries",
        "_zend_str_tolower",
        "_zend_string_init_interned",
        "_zend_strtod",
        "_zend_throw_exception_hook",
        "_zend_write",
        "_zval_internal_ptr_dtor",
        "_zval_ptr_dtor",
        // PHP APIs
        "_php_during_module_startup",
        "_php_get_module_initialized",
        "_php_info_print_table_end",
        "_php_info_print_table_row",
        "_php_info_print_table_start",
        "_php_json_encode",
        "_php_json_parse",
        "_php_json_parser_init",
        "_php_json_serializable_ce",
        "_php_version",
        "_php_version_id",
        // SAPI
        "_sapi_getenv",
        "_sapi_globals",
        "_sapi_module",
    ];

    for sym in ALLOWED_UNDEFINED_SYMBOLS {
        println!("cargo:rustc-cdylib-link-arg=-Wl,-U,{sym}");
    }
}
