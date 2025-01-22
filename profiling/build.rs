use bindgen::callbacks::IntKind;
use std::collections::HashSet;
use std::{env, fs};
use std::path::Path;
use std::path::PathBuf;
use std::process::Command;

fn main() {
    let php_config_includes_output = Command::new("php-config")
        .arg("--includes")
        .output()
        .expect("Unable to run `php-config`. Is it in your PATH?");

    if !php_config_includes_output.status.success() {
        match String::from_utf8(php_config_includes_output.stderr) {
            Ok(stderr) => panic!("`php-config failed: {}", stderr),
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
    println!("cargo:rustc-env=PROFILER_VERSION={}", version);
    println!("cargo:rerun-if-changed=../VERSION");

    let php_config_includes = std::str::from_utf8(php_config_includes_output.stdout.as_slice())
        .expect("`php-config`'s stdout to be valid utf8");

    let vernum = php_config_vernum();
    let post_startup_cb = cfg_post_startup_cb(vernum);
    let preload = cfg_preload(vernum);
    let fibers = cfg_fibers(vernum);
    let run_time_cache = cfg_run_time_cache(vernum);
    let trigger_time_sample = cfg_trigger_time_sample();
    let zend_error_observer = cfg_zend_error_observer(vernum);

    generate_bindings(php_config_includes, fibers, zend_error_observer);
    build_zend_php_ffis(
        php_config_includes,
        post_startup_cb,
        preload,
        run_time_cache,
        fibers,
        trigger_time_sample,
        zend_error_observer,
        vernum,
    );

    cfg_php_major_version(vernum);
    cfg_php_feature_flags(vernum);
    cfg_zts();
}

fn php_config_vernum() -> u64 {
    let output = Command::new("php-config")
        .arg("--vernum")
        .output()
        .expect("Unable to run `php-config`. Is it in your PATH?");

    if !output.status.success() {
        match String::from_utf8(output.stderr) {
            Ok(stderr) => panic!("`php-config --vernum` failed: {}", stderr),
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
    "../zend_abstract_interface/env/env.h",
    "../zend_abstract_interface/exceptions/exceptions.h",
    "../zend_abstract_interface/json/json.h",
];

#[allow(clippy::too_many_arguments)]
fn build_zend_php_ffis(
    php_config_includes: &str,
    post_startup_cb: bool,
    preload: bool,
    run_time_cache: bool,
    fibers: bool,
    trigger_time_sample: bool,
    zend_error_observer: bool,
    vernum: u64,
) {
    println!("cargo:rerun-if-changed=src/php_ffi.h");
    println!("cargo:rerun-if-changed=src/php_ffi.c");
    println!("cargo:rerun-if-changed=../ext/handlers_api.c");
    println!("cargo:rerun-if-changed=../ext/handlers_api.h");

    let sandbox = if vernum < 80000 {
        "../zend_abstract_interface/sandbox/php7/sandbox.c"
    } else {
        "../zend_abstract_interface/sandbox/php8/sandbox.c"
    };

    // Profiling only needs config, exceptions and its dependencies.
    let zai_c_files = [
        "../zend_abstract_interface/config/config_decode.c",
        "../zend_abstract_interface/config/config_ini.c",
        "../zend_abstract_interface/config/config.c",
        "../zend_abstract_interface/config/config_runtime.c",
        "../zend_abstract_interface/env/env.c",
        "../zend_abstract_interface/exceptions/exceptions.c",
        "../zend_abstract_interface/symbols/lookup.c",
        sandbox,
        "../zend_abstract_interface/json/json.c",
        "../zend_abstract_interface/zai_string/string.c",
    ];

    for file in zai_c_files.iter().chain(ZAI_H_FILES.iter()) {
        println!("cargo:rerun-if-changed={}", *file);
    }

    let output = Command::new("php-config")
        .arg("--prefix")
        .output()
        .expect("Unable to run `php-config`. Is it in your PATH?");

    let prefix = String::from_utf8(output.stdout).expect("only utf8 chars work");
    println!("cargo:rustc-link-search=native={}/lib", prefix.trim());

    let files = ["src/php_ffi.c", "../ext/handlers_api.c"];
    let post_startup_cb = if post_startup_cb { "1" } else { "0" };
    let preload = if preload { "1" } else { "0" };
    let fibers = if fibers { "1" } else { "0" };
    let run_time_cache = if run_time_cache { "1" } else { "0" };
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
        .define("CFG_STACK_WALKING_TESTS", stack_walking_tests)
        .define("CFG_TRIGGER_TIME_SAMPLE", trigger_time_sample)
        .define("CFG_ZEND_ERROR_OBSERVER", zend_error_observer)
        .includes([Path::new("../ext")])
        .includes(
            str::replace(php_config_includes, "-I", "")
                .split(' ')
                .map(Path::new)
                .chain([Path::new("../zend_abstract_interface")]),
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
    if vernum >= 70300 {
        println!("cargo:rustc-cfg=php_post_startup_cb");
        true
    } else {
        false
    }
}

fn cfg_preload(vernum: u64) -> bool {
    if vernum >= 70400 {
        println!("cargo:rustc-cfg=php_preload");
        true
    } else {
        false
    }
}

fn cfg_run_time_cache(vernum: u64) -> bool {
    if vernum >= 80000 {
        println!("cargo:rustc-cfg=php_run_time_cache");
        true
    } else {
        false
    }
}

fn cfg_trigger_time_sample() -> bool {
    env::var("CARGO_FEATURE_TRIGGER_TIME_SAMPLE").is_ok()
}

fn cfg_zend_error_observer(vernum: u64) -> bool {
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
    if vernum >= 80100 {
        println!("cargo:rustc-cfg=php_has_fibers");
        true
    } else {
        false
    }
}

fn cfg_php_feature_flags(vernum: u64) {
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
    let output = Command::new("php")
        .arg("-n")
        .arg("-r")
        .arg("echo PHP_ZTS, PHP_EOL;")
        .output()
        .expect("Unable to run `php`. Is it in your PATH?");

    if !output.status.success() {
        match String::from_utf8(output.stderr) {
            Ok(stderr) => panic!("`php failed: {}", stderr),
            Err(err) => panic!("`php` failed, not utf8: {}", err),
        }
    }

    let zts =
        std::str::from_utf8(output.stdout.as_slice()).expect("`php`'s stdout to be valid utf8");

    if zts.contains('1') {
        println!("cargo:rustc-cfg=php_zts");
    }
}
