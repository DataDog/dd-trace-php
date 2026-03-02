use bindgen::callbacks::IntKind;
use std::collections::HashMap;
use std::collections::HashSet;
use std::path::Path;
use std::path::PathBuf;
use std::sync::{Arc, Mutex, OnceLock};
use std::{env, fs};

fn main() {
    let php_includes = build_var("PHP_INCLUDES");
    let php_include_dir = build_var("PHP_INCLUDE_DIR");
    let php_prefix = build_var("PHP_PREFIX");
    let php_version_id = php_version_id(&php_includes, &php_include_dir);

    // Read the version from the VERSION file
    let version = fs::read_to_string("../VERSION")
        .expect("Failed to read VERSION file")
        .trim()
        .to_string();
    println!("cargo:rustc-env=PROFILER_VERSION={version}");
    println!("cargo:rerun-if-changed=../VERSION");

    let vernum = php_version_id;
    let post_startup_cb = cfg_post_startup_cb(vernum);
    let preload = cfg_preload(vernum);
    let fibers = cfg_fibers(vernum);
    let run_time_cache = cfg_run_time_cache(vernum);
    let trigger_time_sample = cfg_trigger_time_sample();
    let zend_error_observer = cfg_zend_error_observer(vernum);

    generate_bindings(&php_includes, fibers, zend_error_observer);
    build_zend_php_ffis(
        &php_includes,
        &php_prefix,
        post_startup_cb,
        preload,
        run_time_cache,
        fibers,
        trigger_time_sample,
        zend_error_observer,
    );

    cfg_php_major_version(vernum);
    cfg_php_feature_flags(vernum);
    cfg_zts_from_headers(&php_include_dir);
}

fn build_var(name: &str) -> String {
    if let Some(value) = makefile_var(name) {
        return value;
    }

    panic!(
        "Missing required build variable {name}. \
Run phpize && ./configure to generate Makefile."
    )
}

fn php_version_id(php_includes: &str, php_include_dir: &str) -> u64 {
    let macros = php_header_macros(
        "main/php_version.h",
        php_includes,
        php_include_dir,
        &["PHP_VERSION_ID"],
    );
    macros
        .get("PHP_VERSION_ID")
        .copied()
        .unwrap_or_else(|| panic!("PHP_VERSION_ID not found in php_config.h")) as u64
}

fn makefile_vars() -> Option<&'static HashMap<String, String>> {
    static MAKEFILE_VARS: OnceLock<Option<HashMap<String, String>>> = OnceLock::new();
    MAKEFILE_VARS
        .get_or_init(|| {
            let path = Path::new("Makefile");
            if !path.exists() {
                return None;
            }
            println!("cargo:rerun-if-changed=Makefile");
            let contents = fs::read_to_string(path).ok()?;
            let mut vars = HashMap::new();
            for line in contents.lines() {
                if let Some((key, value)) = line.split_once('=') {
                    let key = key.trim();
                    let value = value.trim();
                    if !key.is_empty() {
                        vars.insert(key.to_string(), value.to_string());
                    }
                }
            }
            Some(vars)
        })
        .as_ref()
}

fn makefile_var(name: &str) -> Option<String> {
    let vars = makefile_vars()?;
    let key = match name {
        "PHP_INCLUDES" => "INCLUDES",
        "PHP_INCLUDE_DIR" => "phpincludedir",
        "PHP_PREFIX" => "prefix",
        "PHP_VERSION_ID" => "DATADOG_PHP_VERSION_ID",
        _ => return None,
    };
    vars.get(key).cloned()
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
    php_prefix: &str,
    post_startup_cb: bool,
    preload: bool,
    run_time_cache: bool,
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

    println!(
        "cargo:rustc-link-search=native={prefix}/lib",
        prefix = php_prefix.trim()
    );

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

fn cfg_zts_from_headers(php_include_dir: &str) {
    println!("cargo::rustc-check-cfg=cfg(php_zts)");
    if php_header_macros("main/php_config.h", "", php_include_dir, &["ZTS"])
        .get("ZTS")
        .copied()
        .unwrap_or(0)
        != 0
    {
        println!("cargo:rustc-cfg=php_zts");
    }
}

fn php_header_macros(
    header: &str,
    php_includes: &str,
    php_include_dir: &str,
    interested: &[&str],
) -> HashMap<String, i64> {
    let header_path = Path::new(php_include_dir.trim()).join(header);
    println!("cargo:rerun-if-changed={}", header_path.display());

    let macros = Arc::new(Mutex::new(HashMap::<String, i64>::new()));
    let callbacks = MacroCollector {
        macros: Arc::clone(&macros),
        interested: interested.iter().map(|s| s.to_string()).collect(),
    };

    let mut builder = bindgen::Builder::default()
        .header(header_path.to_string_lossy().to_string())
        .parse_callbacks(Box::new(callbacks));

    for arg in php_includes.split_whitespace() {
        builder = builder.clang_arg(arg);
    }
    if !php_include_dir.trim().is_empty() {
        builder = builder.clang_arg(format!("-I{}", php_include_dir.trim()));
    }

    builder
        .generate()
        .expect("bindgen failed to parse PHP headers");

    let map = macros.lock().unwrap();
    map.clone()
}

#[derive(Debug)]
struct MacroCollector {
    macros: Arc<Mutex<HashMap<String, i64>>>,
    interested: HashSet<String>,
}

impl bindgen::callbacks::ParseCallbacks for MacroCollector {
    fn int_macro(&self, name: &str, value: i64) -> Option<IntKind> {
        if self.interested.contains(name) {
            self.macros
                .lock()
                .expect("macro map lock to succeed")
                .insert(name.to_string(), value);
        }
        None
    }
}
