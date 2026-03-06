extern crate core;

pub mod bindings;
pub mod capi;
mod clocks;
mod config;
mod logging;
pub mod module_globals;
pub mod profiling;
mod pthread;
mod sapi;
mod universal;
mod wall_time;

mod string_set;

#[macro_use]
mod allocation;

#[cfg(all(feature = "io_profiling", target_os = "linux"))]
mod io;

mod exception;

mod timeline;
mod vec_ext;
mod zend_string;

use crate::config::SystemSettings;
use crate::zend::get_sapi_request_info;
use bindings::{self as zend, ZendExtension, ZendExtensionVersionInfo, ZendResult};
use clocks::*;
use core::ffi::{c_char, c_int, c_void};
use core::ptr;
use lazy_static::lazy_static;
use libdd_common::{cstr, tag, tag::Tag};
use log::{debug, error, info, trace, warn};
use profiling::{LocalRootSpanResourceMessage, Profiler, VmInterrupt};
use sapi::Sapi;
use std::borrow::Cow;
use std::cell::{BorrowError, BorrowMutError, Cell, RefCell};
use std::ffi::CStr;
use std::mem::MaybeUninit;
use std::ops::{Deref, DerefMut};
use std::sync::atomic::{AtomicBool, AtomicI32, AtomicPtr, AtomicU32, Ordering};
use std::sync::{Arc, Once, OnceLock};
use std::thread::{AccessError, LocalKey};
use std::time::{Duration, Instant};
use uuid::Uuid;

thread_local! {
    /// Tracks whether the current thread is within the GINIT–GSHUTDOWN window.
    /// Set to `true` at the very start of `ginit`, cleared to `false` at the
    /// very end of `gshutdown`. Used to back the `debug_assert!` in
    /// [`OnPhpThread::new`] and to guard optional shutdown work in the
    /// post-fork child handler.
    pub(crate) static ON_PHP_THREAD_ACTIVE: Cell<bool> = const { Cell::new(false) };
}

/// Returns `true` if the current thread is within the GINIT–GSHUTDOWN window.
#[inline]
pub(crate) fn is_on_php_thread() -> bool {
    ON_PHP_THREAD_ACTIVE.with(Cell::get)
}

/// Token that proves the current code is executing on a PHP thread within the
/// window from `GINIT` through the end of `GSHUTDOWN` (inclusive).
///
/// Not [`Send`] — the guarantee is only valid for the thread that created it.
/// `Copy` because being on a PHP thread is a property of the thread, not a
/// resource that can be consumed.
#[derive(Clone, Copy)]
pub struct OnPhpThread(core::marker::PhantomData<*const ()>);

impl OnPhpThread {
    /// Assert that this code is running on a PHP thread between GINIT and
    /// GSHUTDOWN (inclusive).
    ///
    /// # Safety
    /// The caller must be executing on a PHP thread at a point no earlier
    /// than GINIT and no later than GSHUTDOWN for that thread.
    pub unsafe fn new() -> Self {
        let result = Self::try_new();
        debug_assert!(
            result.is_some(),
            "OnPhpThread::new() called outside GINIT–GSHUTDOWN window"
        );

        // SAFETY: the caller promised this, and we've debug checked it.
        unsafe { result.unwrap_unchecked() }
    }

    /// Returns `Some` if the current thread is within the GINIT–GSHUTDOWN
    /// window, `None` otherwise. Safe to call from any thread.
    pub fn try_new() -> Option<Self> {
        if is_on_php_thread() {
            Some(OnPhpThread(core::marker::PhantomData))
        } else {
            None
        }
    }
}

/// Name of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_NAME: &CStr = c"datadog-profiling";

/// Version of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_VERSION: &[u8] = concat!(env!("PROFILER_VERSION"), "\0").as_bytes();

/// Version ID of PHP at run-time. Initialized to 0 and overwritten at load time.
static RUNTIME_PHP_VERSION_ID: AtomicU32 = AtomicU32::new(0);

/// Version str of PHP at run-time. Initialized to "" and overwritten at load time.
static mut RUNTIME_PHP_VERSION: &str = "";

/// Set to `true` once `ddog_php_prof_post_startup_cb` fires (PHP 7.3+), or
/// immediately at startup time for PHP 7.1/7.2 which lack the callback.
static IS_POST_STARTUP: AtomicBool = AtomicBool::new(false);

/// The original value of `zend_post_startup_cb` that we displaced when
/// installing our hook. `null` means no prior hook was registered.
static ORIG_POST_STARTUP_CB: AtomicPtr<c_void> = AtomicPtr::new(ptr::null_mut());

/// Returns `true` once the PHP post-startup phase has completed.
///
/// On PHP 7.1/7.2 this is always `true` (those versions have no post-startup
/// callback and no preloading). On PHP 7.3+ it becomes `true` when
/// `zend_post_startup_cb` fires.
pub fn is_post_startup() -> bool {
    IS_POST_STARTUP.load(Ordering::Relaxed)
}

/// Our `zend_post_startup_cb` hook installed by [`post_startup_init`].
///
/// Chains to the previous hook (if any), then sets [`IS_POST_STARTUP`].
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_post_startup_cb() -> ZendResult {
    // ORIG_POST_STARTUP_CB is null when no other extension registered a hook
    // before us. PHP initialises zend_post_startup_cb to NULL, so null means
    // "nobody else was there" — calling a null pointer would crash.
    let ptr = ORIG_POST_STARTUP_CB.load(Ordering::Relaxed);
    if !ptr.is_null() {
        let orig: unsafe extern "C" fn() -> ZendResult = core::mem::transmute(ptr);
        if orig() != ZendResult::Success {
            return ZendResult::Failure;
        }
    }
    IS_POST_STARTUP.store(true, Ordering::Release);
    ZendResult::Success
}

/// Installs [`ddog_php_prof_post_startup_cb`] into `zend_post_startup_cb`.
///
/// Called from [`startup`]. On PHP 7.1/7.2 where the symbol doesn't exist,
/// marks post-startup as complete immediately (no preloading on those versions).
fn post_startup_init() {
    // zend_post_startup_cb is a **pointer to a function pointer** in PHP's
    // data segment. symbol_addr returns the address of that variable, so we
    // must read/write through it as a pointer-to-pointer.
    let cb_ptr = universal::runtime::symbol_addr("zend_post_startup_cb");
    if cb_ptr.is_null() {
        // PHP 7.1/7.2: symbol absent, no preloading, mark done immediately.
        IS_POST_STARTUP.store(true, Ordering::Relaxed);
        return;
    }
    let fn_ptr_ptr = cb_ptr as *mut *mut c_void;
    let orig = unsafe { fn_ptr_ptr.read() };
    ORIG_POST_STARTUP_CB.store(orig, Ordering::Relaxed);
    unsafe { fn_ptr_ptr.write(ddog_php_prof_post_startup_cb as *mut c_void) };
}

/// Initialize `RUNTIME_PHP_VERSION` and `RUNTIME_PHP_VERSION_ID`.
///
/// Lookup order:
/// 1. `php_version` runtime symbol, then `_php_version` (some platforms prefix with `_`).
/// 2. `php_version_id` runtime symbol, then `_php_version_id`, for the numeric ID.
/// 3. `fallback_version_ptr` — e.g. a bundled module's `version` field such as Reflection's.
///    Both the string and the numeric ID (parsed from it) come from this pointer.
///
/// The string symbols and the fallback pointer must be `'static` (they must point into the
/// loaded PHP binary, not into temporary storage).
///
/// # Safety
/// Any non-null pointer passed must be a valid, null-terminated, UTF-8 C string.
unsafe fn init_runtime_php_version(fallback_version_ptr: *const u8) {
    use universal::runtime::symbol_addr;

    // php_version() and php_version_id() are functions introduced in PHP 8.3.
    // On older versions the symbols don't exist; symbol_addr returns null.
    // transmute is required — Rust has no as-cast from data pointer to fn pointer.
    type PhpVersionFn = unsafe extern "C" fn() -> *const c_char;
    type PhpVersionIdFn = unsafe extern "C" fn() -> u32;

    // 1. Try php_version() (PHP 8.3+); fall back to Reflection's version field.
    let ver_str_ptr: *const c_char = {
        let p = symbol_addr("php_version");
        if !p.is_null() {
            let f: PhpVersionFn = core::mem::transmute(p);
            f()
        } else {
            fallback_version_ptr as *const c_char
        }
    };
    if !ver_str_ptr.is_null() {
        if let Ok(s) = CStr::from_ptr(ver_str_ptr).to_str() {
            RUNTIME_PHP_VERSION = s;
        }
    }

    // 2. Try php_version_id() (PHP 8.3+); fall back to parsing the string set above.
    let p = symbol_addr("php_version_id");
    if !p.is_null() {
        let f: PhpVersionIdFn = core::mem::transmute(p);
        RUNTIME_PHP_VERSION_ID.store(f(), Ordering::Relaxed);
    } else if !RUNTIME_PHP_VERSION.is_empty() {
        let mut parts = RUNTIME_PHP_VERSION.split('.');
        let major: u32 = parts.next().and_then(|p| p.parse().ok()).unwrap_or(0);
        let minor: u32 = parts.next().and_then(|p| p.parse().ok()).unwrap_or(0);
        // Patch may have a suffix like "-dev"; take only leading digits.
        let patch: u32 = parts
            .next()
            .and_then(|p| p.split(|c: char| !c.is_ascii_digit()).next())
            .and_then(|p| p.parse().ok())
            .unwrap_or(0);
        if major > 0 {
            RUNTIME_PHP_VERSION_ID.store(major * 10000 + minor * 100 + patch, Ordering::Relaxed);
        }
    }
}

lazy_static! {
    /// # Safety
    /// The first time this is accessed must be after config is initialized in
    /// the first RINIT and before mshutdown!
    static ref GLOBAL_TAGS: Vec<Tag> = {
        let mut tags = vec![
            tag!("language", "php"),
            tag!("profiler_version", env!("PROFILER_VERSION")),
            // SAFETY: calling getpid() is safe.
            Tag::new("process_id", unsafe { libc::getpid() }.to_string())
                .expect("process_id tag to be valid"),
            Tag::new("runtime-id", runtime_id().to_string()).expect("runtime-id tag to be valid"),
        ];

        // This should probably be "language_version", but this is the
        // standardized tag name.
        // SAFETY: PHP_VERSION is safe to access in rinit (only
        // mutated during minit).
        add_tag(&mut tags, "runtime_version", unsafe { RUNTIME_PHP_VERSION });
        add_tag(&mut tags, "php.sapi", SAPI.as_ref());
        // In case we ever add PHP debug build support, we should add `zend-zts-debug` and
        // `zend-nts-debug`. For the time being we only support `zend-zts-ndebug` and
        // `zend-nts-ndebug`
        let runtime_engine = if universal::is_zts() {
            "zend-zts-ndebug"
        } else {
            "zend-nts-ndebug"
        };
        add_tag(&mut tags, "runtime_engine", runtime_engine);
        tags
    };

    /// The Server API the profiler is running under.
    static ref SAPI: Sapi = {
        #[cfg(not(test))]
        {
            let sapi_module = &raw const zend::sapi_module;
            // SAFETY: sapi_module is initialized before minit and there
            // should be no concurrent threads.
            let sapi_name = unsafe { (*sapi_module).name };
            if sapi_name.is_null() {
                panic!("the sapi_module's name is a null pointer");
            }

            // SAFETY: value has been checked for NULL; I haven't checked that the
            // engine ensures its length is less than `isize::MAX`, but it is a
            // risk I'm willing to take.
            let sapi_name = unsafe { CStr::from_ptr(sapi_name) };
            Sapi::from_name(sapi_name.to_string_lossy().as_ref())
        }
        // When running `cargo test` we do not link against PHP, so `zend::sapi_name` is not
        // available and we just return `Sapi::Unkown`
        #[cfg(test)]
        {
            Sapi::Unknown
        }
    };

    // SAFETY: PROFILER_NAME is a byte slice that satisfies the safety requirements.
    // Panic: we own this string and it should be UTF8 (see PROFILER_NAME above).
    static ref PROFILER_NAME_STR: &'static str = PROFILER_NAME.to_str().unwrap();

    // SAFETY: PROFILER_VERSION is a byte slice that satisfies the safety requirements.
    static ref PROFILER_VERSION_STR: &'static str = unsafe { CStr::from_ptr(PROFILER_VERSION.as_ptr() as *const c_char) }
        .to_str()
        // Panic: we own this string and it should be UTF8 (see PROFILER_VERSION above).
        .unwrap();
}

/// The runtime ID, which is basically a universally unique "pid". This makes
/// it almost const, the exception being to re-initialize it from a child fork
/// handler. We don't yet support forking, so we use OnceCell.
/// Additionally, the tracer is going to ask for this in its ACTIVATE handler,
/// so whatever it is replaced with needs to also follow the
/// initialize-on-first-use pattern.
static RUNTIME_ID: OnceLock<Uuid> = OnceLock::new();

/// Module dependencies for the profiler extension.
static MODULE_DEPS: [zend::ModuleDep; 8] = [
    zend::ModuleDep::required(cstr!("standard")),
    zend::ModuleDep::required(cstr!("json")),
    zend::ModuleDep::optional(cstr!("ddtrace")),
    // Optionally, be dependent on these event extensions so that the functions they provide
    // are registered in the function table and we can hook into them.
    zend::ModuleDep::optional(cstr!("ev")),
    zend::ModuleDep::optional(cstr!("event")),
    zend::ModuleDep::optional(cstr!("libevent")),
    zend::ModuleDep::optional(cstr!("uv")),
    zend::ModuleDep::end(),
];

/// Guard to ensure zend_extension is registered at most once, even if
/// get_module is called multiple times.
static ZEND_EXTENSION_REGISTER_ONCE: Once = Once::new();

/// Has our module been registered? Set when we register via
/// zend_register_internal_module (zend_extension path) or when the engine
/// registers it after get_module (module path).
static MODULE_REGISTERED: AtomicBool = AtomicBool::new(false);

/// Has our zend_extension been registered? Set when we register the hybrid
/// in get_module (module path), or when the engine registers it (zend_extension
/// path—set in build_id_check before returning SUCCESS).
static ZEND_EXTENSION_REGISTERED: AtomicBool = AtomicBool::new(false);

/// API number from the engine, stored in api_no_check for use in build_id_check.
static LAST_API_NO: AtomicI32 = AtomicI32::new(0);

/// Placeholder build_id so the engine always invokes api_no_check/build_id_check.
static BUILD_ID_PLACEHOLDER: &CStr = c"API0,NTS";

/// Static buffer for module build_id (extension format → module format).
/// Extension: API420240924,NTS. Module: API20240924,NTS (remove 4th char).
static mut MODULE_BUILD_ID_BUF: [u8; 32] = [0; 32];

/// Wrapper that embeds the matrix entry and executor_globals cache with the module.
/// Module is first so PHP sees valid layout.
/// Initialized only when we register; MODULE_REGISTERED implies this is init.
#[repr(C)]
struct ProfilingModuleEntry {
    module: zend::ModuleEntry,
    matrix_entry: &'static universal::MatrixEntry,
    executor_globals_cache: universal::ExecutorGlobalsCache,
}

static mut PROFILER_MODULE: MaybeUninit<ProfilingModuleEntry> = MaybeUninit::uninit();

/// In unit tests, a lightweight matrix entry override so tests don't need a
/// fully initialised `PROFILER_MODULE`. Set via [`init_matrix_for_tests`].
#[cfg(test)]
static TEST_MATRIX_ENTRY: std::sync::OnceLock<&'static universal::MatrixEntry> =
    std::sync::OnceLock::new();

/// Inject a `MatrixEntry` for unit tests that exercise code paths which call
/// [`matrix_entry`]. Picks the first NTS entry from the compile-time table.
#[cfg(test)]
pub(crate) fn init_matrix_for_tests() {
    TEST_MATRIX_ENTRY.get_or_init(|| {
        universal::first_nts_entry()
            .expect("at least one NTS entry in the compile-time matrix table")
    });
}

/// Matrix entry for the loaded profiler. Infallible—only call when module is loaded.
pub(crate) fn matrix_entry() -> &'static universal::MatrixEntry {
    #[cfg(test)]
    if let Some(entry) = TEST_MATRIX_ENTRY.get() {
        return entry;
    }
    debug_assert!(
        MODULE_REGISTERED.load(Ordering::SeqCst),
        "matrix_entry called before module registered; MaybeUninit not yet initialized"
    );
    // SAFETY: MODULE_REGISTERED is set only after we write PROFILER_MODULE; callbacks only run when registered
    unsafe { PROFILER_MODULE.assume_init_ref().matrix_entry }
}

/// Cached executor_globals symbols. Infallible—only call when module is loaded.
pub(crate) fn executor_globals_cache() -> &'static universal::ExecutorGlobalsCache {
    debug_assert!(
        MODULE_REGISTERED.load(Ordering::SeqCst),
        "executor_globals_cache called before module registered; MaybeUninit not yet initialized"
    );
    // SAFETY: MODULE_REGISTERED is set only after we write PROFILER_MODULE; callbacks only run when registered
    unsafe { &PROFILER_MODULE.assume_init_ref().executor_globals_cache }
}

/// Base module entry (const parts). Variable fields (functions, build_id, handle, zts, globals) set at init.
fn base_module_entry(is_zts: bool) -> zend::ModuleEntry {
    let globals = if is_zts {
        zend::ModuleGlobalsUnion {
            globals_id_ptr: ptr::addr_of_mut!(module_globals::GLOBALS_ID),
        }
    } else {
        zend::ModuleGlobalsUnion {
            globals_ptr: ptr::addr_of_mut!(module_globals::GLOBALS).cast(),
        }
    };
    zend::ModuleEntry {
        deps: MODULE_DEPS.as_ptr(),
        name: PROFILER_NAME.as_ptr(),
        functions: ptr::null(),
        module_startup_func: Some(minit),
        module_shutdown_func: Some(mshutdown),
        request_startup_func: Some(rinit),
        request_shutdown_func: Some(rshutdown),
        info_func: Some(minfo),
        version: PROFILER_VERSION.as_ptr(),
        globals_size: core::mem::size_of::<module_globals::ProfilerGlobals>(),
        globals,
        globals_ctor: Some(module_globals::ginit),
        globals_dtor: Some(module_globals::gshutdown),
        post_deactivate_func: Some(prshutdown),
        build_id: ptr::null(),
        ..zend::ModuleEntry::new()
    }
}

/// ZEND_EXTENSION_API_NO for PHP 7.1. Any api_no below this is too old.
const PHP71_MIN_EXTENSION_API_NO: i32 = 320160303;

/// Prepare profiler for registration. Returns Some((entry, cache)) iff all checks pass.
fn prepare_profiler_module(
    api_no: i32,
    build_id: &str,
    is_zts: bool,
) -> Option<(
    &'static universal::MatrixEntry,
    universal::ExecutorGlobalsCache,
)> {
    if api_no < PHP71_MIN_EXTENSION_API_NO {
        error!(
            "datadog-profiling: PHP version is too old (api_no={}); minimum supported version is PHP 7.1",
            api_no
        );
        return None;
    }
    universal::find_entry(api_no, build_id, is_zts, false).and_then(|entry| {
        let cache = universal::build_executor_globals_cache(entry)?;
        // Don't eagerly validate cache.get() here: for ZTS the thread-local
        // storage isn't set up until the first request, so the pointer is always
        // null at module-registration time. Defer validation to request time for
        // consistency across NTS and ZTS.
        Some((entry, cache))
    })
}

/// Converts extension build_id (API420240924,NTS) to module build_id (API20240924,NTS)
/// by removing the 4th character. Returns pointer to static buffer or null on failure.
unsafe fn extension_to_module_build_id(build_id: *const c_char) -> *const c_char {
    if build_id.is_null() {
        return ptr::null();
    }
    let s = CStr::from_ptr(build_id).to_bytes();
    if s.len() < 12 || s.len() > 32 {
        return ptr::null();
    }
    // Copy first 3 chars (API), then from index 4 onwards (skip 4th char).
    let buf = ptr::addr_of_mut!(MODULE_BUILD_ID_BUF);
    ptr::copy_nonoverlapping(s.as_ptr(), (*buf).as_mut_ptr(), 3);
    ptr::copy_nonoverlapping(s.as_ptr().add(4), (*buf).as_mut_ptr().add(3), s.len() - 4);
    (*buf)[s.len() - 1] = b'\0';
    (*buf).as_ptr().cast::<c_char>()
}

unsafe extern "C" fn api_no_check(api_no: c_int) -> ZendResult {
    LAST_API_NO.store(api_no, Ordering::Relaxed);
    ZendResult::Success
}

unsafe extern "C" fn build_id_check(build_id: *const c_char) -> ZendResult {
    if MODULE_REGISTERED.load(Ordering::SeqCst) {
        error!("datadog-profiling already loaded; cannot load as zend_extension= again");
        return ZendResult::Failure;
    }

    let module_build_id_ptr = extension_to_module_build_id(build_id);
    if module_build_id_ptr.is_null() {
        return ZendResult::Failure;
    }

    let runtime_build_id = CStr::from_ptr(build_id).to_string_lossy();

    if runtime_build_id.contains(",debug") {
        error!(
            "datadog-profiling: debug PHP builds are not supported (build_id={})",
            runtime_build_id
        );
        return ZendResult::Failure;
    }

    let is_zts = universal::build_id_is_zts(build_id);

    let api_no = LAST_API_NO.load(Ordering::Relaxed);
    let (entry, executor_globals_cache) = match prepare_profiler_module(
        api_no,
        runtime_build_id.as_ref(),
        is_zts,
    ) {
        Some(p) => p,
        None => {
            error!(
                "datadog-profiling: no matrix entry or missing PHP symbols for api_no={} build_id={} zts={}",
                api_no, runtime_build_id, is_zts
            );
            return ZendResult::Failure;
        }
    };

    let mut module = base_module_entry(is_zts);
    ptr::addr_of_mut!(module.functions).write(bindings::ddog_php_prof_functions.0);
    ptr::addr_of_mut!(module.build_id).write(module_build_id_ptr);
    ptr::addr_of_mut!(module.handle).write(ptr::null_mut());
    module.zts = if is_zts { 1 } else { 0 };
    // Patch the compile-time zend_api constant to match the runtime PHP version.
    module.zend_api = (api_no % 100_000_000) as u32;

    // Set PHP version: try php_version/php_version_id symbols first, fall back to Reflection's
    // version field if available (Reflection may not be loaded yet at build_id_check time).
    let refl = find_module_entry(c"reflection");
    let refl_version = if refl.is_null() {
        ptr::null()
    } else {
        (*refl).version
    };
    init_runtime_php_version(refl_version);

    let pm = ptr::addr_of_mut!(PROFILER_MODULE);
    unsafe {
        (*pm).write(ProfilingModuleEntry {
            module,
            matrix_entry: entry,
            executor_globals_cache,
        })
    };

    // Set MODULE_REGISTERED before zend_register_internal_module: in ZTS mode that call
    // triggers ts_allocate_fast_id → ginit for every existing thread, and ginit reads
    // from PROFILER_MODULE (which is now initialized above).
    MODULE_REGISTERED.store(true, Ordering::SeqCst);
    ZEND_EXTENSION_REGISTERED.store(true, Ordering::SeqCst);

    let registered = unsafe {
        zend::zend_register_internal_module(ptr::addr_of_mut!((*pm).assume_init_mut().module))
    };
    if registered.is_null() {
        // Roll back the flag — we failed to register.
        MODULE_REGISTERED.store(false, Ordering::SeqCst);
        ZEND_EXTENSION_REGISTERED.store(false, Ordering::SeqCst);
        return ZendResult::Failure;
    }

    ZendResult::Success
}

/// Exported for zend_extension= loading. Placeholder forces engine to call
/// api_no_check and build_id_check.
#[no_mangle]
pub static mut extension_version_info: ZendExtensionVersionInfo = ZendExtensionVersionInfo {
    zend_extension_api_no: 0,
    build_id: BUILD_ID_PLACEHOLDER.as_ptr(),
};

/// Exported for zend_extension= loading.
#[no_mangle]
pub static mut zend_extension_entry: ZendExtension = ZendExtension {
    name: PROFILER_NAME.as_ptr(),
    version: PROFILER_VERSION.as_ptr().cast::<c_char>(),
    author: c"Datadog".as_ptr(),
    url: c"https://github.com/DataDog/dd-trace-php".as_ptr(),
    copyright: c"Copyright Datadog".as_ptr(),
    startup: Some(startup),
    shutdown: Some(shutdown),
    activate: Some(activate),
    deactivate: None,
    message_handler: None,
    op_array_handler: None,
    statement_handler: None,
    fcall_begin_handler: None,
    fcall_end_handler: None,
    op_array_ctor: None,
    op_array_dtor: None,
    api_no_check: Some(api_no_check),
    build_id_check: Some(build_id_check),
    op_array_persist_calc: None,
    op_array_persist: None,
    reserved5: ptr::null_mut(),
    reserved6: ptr::null_mut(),
    reserved7: ptr::null_mut(),
    reserved8: ptr::null_mut(),
    handle: ptr::null_mut(),
    resource_number: -1,
};

/// Reflection module name for obtaining api_no/build_id when loaded as extension=.
/// The function `get_module` is what makes this a PHP module.
///
/// # Safety
///
/// Do not call this function manually; it will be called by the engine.
/// Generally it is  only called once, but if someone accidentally loads the
/// module twice then it might get called more than once, though it will warn
/// and not use the consecutive return value.
#[no_mangle]
pub unsafe extern "C" fn get_module() -> *mut zend::ModuleEntry {
    let pm = ptr::addr_of_mut!(PROFILER_MODULE);

    let reflection_module = find_module_entry(c"reflection");

    // extension= path: engine does not call api_no_check/build_id_check.
    // Obtain api_no and build_id from Reflection; require matrix match to load.
    if !ZEND_EXTENSION_REGISTERED.load(Ordering::SeqCst) {
        eprintln!("[ddog-prof] get_module: looking up reflection");
        eprintln!("[ddog-prof] get_module: refl={:p}", reflection_module);
        if reflection_module.is_null() {
            eprintln!("[ddog-prof] get_module: Reflection not found");
            return ptr::null_mut();
        }
        // Set PHP version info as early as possible; the Reflection module's
        // version field always holds the PHP version string and is static.
        unsafe { init_runtime_php_version((*reflection_module).version) };
        let build_id_ptr = unsafe { (*reflection_module).build_id };
        eprintln!(
            "[ddog-prof] get_module: build_id_ptr={:p} refl_size={} our_size={}",
            build_id_ptr,
            unsafe { (*reflection_module).size },
            core::mem::size_of::<zend::ModuleEntry>()
        );
        if build_id_ptr.is_null() {
            eprintln!("[ddog-prof] get_module: build_id null");
            return ptr::null_mut();
        }
        let build_id_bytes = unsafe { CStr::from_ptr(build_id_ptr).to_bytes() };
        eprintln!("[ddog-prof] get_module: build_id={:?}", unsafe {
            CStr::from_ptr(build_id_ptr)
        });
        let (api_no, ext_build_id) =
            match universal::module_to_extension_api_and_build_id(build_id_bytes) {
                Some(t) => t,
                None => {
                    eprintln!("[ddog-prof] get_module: cannot parse build_id");
                    return ptr::null_mut();
                }
            };
        eprintln!(
            "[ddog-prof] get_module: api_no={} ext_build_id={} is_zts={}",
            api_no,
            ext_build_id,
            ext_build_id.contains(",TS")
        );

        if ext_build_id.contains(",debug") {
            eprintln!("[ddog-prof] get_module: debug PHP builds are not supported");
            return ptr::null_mut();
        }

        let is_zts = ext_build_id.contains(",TS");
        let (entry, executor_globals_cache) =
            match prepare_profiler_module(api_no, &ext_build_id, is_zts) {
                Some(p) => p,
                None => {
                    eprintln!(
                        "[ddog-prof] get_module: no matrix entry for api_no={} build_id={} zts={}",
                        api_no, ext_build_id, is_zts
                    );
                    return ptr::null_mut();
                }
            };
        eprintln!("[ddog-prof] get_module: prepare_profiler_module ok");

        let mut module = base_module_entry(is_zts);
        ptr::addr_of_mut!(module.functions).write(bindings::ddog_php_prof_functions.0);
        ptr::addr_of_mut!(module.build_id).write(build_id_ptr);
        ptr::addr_of_mut!(module.handle).write(ptr::null_mut());
        module.zts = if is_zts { 1 } else { 0 };
        module.zend_api = (api_no % 100_000_000) as u32;

        unsafe {
            (*pm).write(ProfilingModuleEntry {
                module,
                matrix_entry: entry,
                executor_globals_cache,
            })
        };
    }

    eprintln!("[ddog-prof] get_module: setting fields");
    // Set fields that aren't const-compatible (may overwrite when zend_extension ran first).
    unsafe {
        let m = ptr::addr_of_mut!((*pm).assume_init_mut().module);
        ptr::addr_of_mut!((*m).functions).write(bindings::ddog_php_prof_functions.0);
        let build_id_ptr = if reflection_module.is_null() {
            ptr::null()
        } else {
            (*reflection_module).build_id
        };
        if !build_id_ptr.is_null() {
            ptr::addr_of_mut!((*m).build_id).write(build_id_ptr);
            // Keep zend_api consistent with build_id.
            let build_id_bytes = CStr::from_ptr(build_id_ptr).to_bytes();
            if let Some((ext_api_no, _)) =
                universal::module_to_extension_api_and_build_id(build_id_bytes)
            {
                (*m).zend_api = (ext_api_no % 100_000_000) as u32;
            }
        }
    }

    // If zend_extension= ran first, we are the second load. Do not register
    // hybrid; return module so PHP fails with "Module already loaded".
    if ZEND_EXTENSION_REGISTERED.load(Ordering::SeqCst) {
        return ptr::addr_of_mut!((*pm).assume_init_mut().module);
    }

    eprintln!("[ddog-prof] get_module: about to call zend_register_extension");
    ZEND_EXTENSION_REGISTER_ONCE.call_once(|| {
        let extension = ZendExtension {
            name: PROFILER_NAME.as_ptr(),
            version: PROFILER_VERSION.as_ptr().cast::<c_char>(),
            author: c"Datadog".as_ptr(),
            url: c"https://github.com/DataDog/dd-trace-php".as_ptr(),
            copyright: c"Copyright Datadog".as_ptr(),
            startup: Some(startup),
            shutdown: Some(shutdown),
            activate: Some(activate),
            ..Default::default()
        };
        unsafe { zend::zend_register_extension(&extension, ptr::null_mut()) };
        eprintln!("[ddog-prof] get_module: zend_register_extension returned");
        ZEND_EXTENSION_REGISTERED.store(true, Ordering::SeqCst);
        MODULE_REGISTERED.store(true, Ordering::SeqCst);
    });
    eprintln!("[ddog-prof] get_module: after register_once");

    let ret = ptr::addr_of_mut!((*pm).assume_init_mut().module);
    eprintln!(
        "[ddog-prof] get_module: returning {:p}, size={}",
        ret,
        unsafe { (*ret).size }
    );
    ret
}

// Important note on the PHP lifecycle:
// Based on how some SAPIs work and the documentation, one might expect that
// MINIT is called once per process, but this is only sort-of true. Some SAPIs
// will call MINIT once and then fork for additional processes.
// This means you cannot do certain things in MINIT and have them work across
// all SAPIs, like spawn threads.
//
// Additionally, when Apache does a reload it will go through the shutdown
// routines and then in the same process do the startup routines, so MINIT can
// actually be called more than once per process as well. This means some
// mechanisms like std::sync::Once::call_once may not be suitable.
// Be careful out there!
extern "C" fn minit(_type: c_int, module_number: c_int) -> ZendResult {
    // todo: merge these lifecycle things to tracing feature?
    // When developing the extension, it's useful to see log messages that
    // occur before the user can configure the log level. However, if we
    // initialized the logger here unconditionally, then they'd have no way to
    // hide these messages. That's why it's done only for debug builds.
    eprintln!("[ddog-prof] MINIT({_type}, {module_number}) enter");
    #[cfg(debug_assertions)]
    {
        logging::log_init(log::LevelFilter::Trace);
        trace!("MINIT({_type}, {module_number})");
    }

    #[cfg(feature = "tracing-subscriber")]
    {
        use std::fs::File;
        use std::os::fd::FromRawFd;
        use std::sync::Mutex;

        let fd = loop {
            // SAFETY:
            let result = unsafe { libc::dup(libc::STDERR_FILENO) };
            if result != -1 {
                break result;
            } else {
                let error = std::io::Error::last_os_error();
                if error.kind() != std::io::ErrorKind::Interrupted {
                    error!("failed duplicating stderr to create tracing subscriber: {error}");
                    return ZendResult::Failure;
                }
            }
        };

        // SAFETY: the file descriptor is both owned and open since the dup
        // call succeeded.
        let writer = Mutex::new(unsafe { File::from_raw_fd(fd) });
        tracing_subscriber::fmt()
            .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
            .with_writer(writer)
            .with_span_events(tracing_subscriber::fmt::format::FmtSpan::CLOSE)
            .init();
    }

    #[cfg(target_vendor = "apple")]
    {
        // If PHP forks and certain ObjC classes are not initialized before the
        // fork, then on High Sierra and above the child process will crash,
        // for example:
        // > objc[25938]: +[__NSCFConstantString initialize] may have been in
        // > progress in another thread when fork() was called. We cannot
        // > safely call it or ignore it in the fork() child process. Crashing
        // > instead. Set a breakpoint on objc_initializeAfterForkError to
        // > debug.
        // In our case, it's things related to TLS that fail, so when we
        // support forking, load this at the beginning:
        // let _ = ddcommon::connector::load_root_certs();
    }

    // RUNTIME_PHP_VERSION is normally set in get_module()/build_id_check() before MINIT.
    // If it is still unset (exotic load order or future SAPI), try again now with
    // Reflection as the string fallback. init_runtime_php_version() always tries the
    // php_version/php_version_id symbols first.
    if RUNTIME_PHP_VERSION_ID.load(Ordering::Relaxed) == 0 {
        let refl = find_module_entry(c"reflection");
        let fallback = if refl.is_null() {
            ptr::null()
        } else {
            unsafe { (*refl).version }
        };
        unsafe { init_runtime_php_version(fallback) };
        if RUNTIME_PHP_VERSION_ID.load(Ordering::Relaxed) == 0 {
            eprintln!(
                "[datadog-profiling] MINIT: failed to detect PHP version; \
                 php_version/_php_version symbols not found and Reflection module unavailable. \
                 Cannot load."
            );
            return ZendResult::Failure;
        }
    }

    config::minit(module_number);

    // Force early initialization of the HTTPS connector while we're still
    // single-threaded. This ensures rustls-native-certs reads SSL_CERT_FILE
    // and SSL_CERT_DIR environment variables safely before any threads are
    // spawned, avoiding potential getenv/setenv race conditions.
    {
        let _connector = libdd_common::connector::Connector::default();
    }

    // Initialize the lazy lock holding the env var for new origin detection.
    _ = std::sync::LazyLock::force(&libdd_common::entity_id::DD_EXTERNAL_ENV);

    // SAFETY: during minit there shouldn't be any threads to race against these writes.
    unsafe { wall_time::minit() };

    timeline::timeline_minit();

    exception::exception_profiling_minit();

    // There are a few things which need to do something on the first rinit of
    // each minit/mshutdown cycle. In Apache, when doing `apachectl graceful`,
    // there can be more than one of these cycles per process.
    // Re-initializing these on each minit allows us to do it once per cycle.
    // This is unsafe generally, but all SAPIs are supposed to only have one
    // thread alive during minit, so it should be safe here specifically.
    unsafe {
        ZAI_CONFIG_ONCE = Once::new();
        RINIT_ONCE = Once::new();
    }

    ZendResult::Success
}

extern "C" fn prshutdown() -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("PRSHUTDOWN");

    // ZAI config may be accessed indirectly via other modules RSHUTDOWN, so
    // delay this until the last possible time.
    bindings::ddog_php_prof_config_rshutdown();

    timeline::timeline_prshutdown();

    ZendResult::Success
}

pub struct RequestLocals {
    pub env: Option<String>,
    pub service: Option<String>,
    pub version: Option<String>,
    pub git_commit_sha: Option<String>,
    pub git_repository_url: Option<String>,
    pub tags: Vec<Tag>,

    /// SystemSettings are global. Note that if this is being read in fringe
    /// conditions such as in mshutdown when there were no requests served,
    /// then the settings are still memory safe, but they may not have the
    /// real configuration. Instead, they have a best-effort values such as
    /// the initial settings, or possibly the values which were available
    /// in MINIT.
    pub system_settings: ptr::NonNull<SystemSettings>,

    pub interrupt_count: AtomicU32,
    pub vm_interrupt_addr: *const AtomicBool,
}

impl RequestLocals {
    #[track_caller]
    pub fn system_settings(&self) -> &SystemSettings {
        // SAFETY: it should always be valid, just maybe "stale", such as
        // having only the initial values, or only the ones available in minit,
        // rather than the fully configured values.
        unsafe { self.system_settings.as_ref() }
    }
}

impl Default for RequestLocals {
    fn default() -> RequestLocals {
        RequestLocals {
            env: None,
            service: None,
            version: None,
            git_commit_sha: None,
            git_repository_url: None,
            tags: vec![],
            system_settings: SystemSettings::get(),
            interrupt_count: AtomicU32::new(0),
            vm_interrupt_addr: ptr::null_mut(),
        }
    }
}

#[derive(thiserror::Error, Debug)]
pub enum RefCellExtError {
    #[error(transparent)]
    AccessError(#[from] AccessError),

    #[error("non-mutable borrow while mutably borrowed")]
    BorrowError(#[from] BorrowError),

    #[error("mutable borrow while mutably borrowed")]
    BorrowMutError(#[from] BorrowMutError),
}

trait RefCellExt<T> {
    fn try_with_borrow<F, R>(&'static self, f: F) -> Result<R, RefCellExtError>
    where
        F: FnOnce(&T) -> R;

    fn try_with_borrow_mut<F, R>(&'static self, f: F) -> Result<R, RefCellExtError>
    where
        F: FnOnce(&mut T) -> R;

    fn borrow_or_false<F>(&'static self, f: F) -> bool
    where
        F: FnOnce(&T) -> bool,
    {
        self.try_with_borrow(f).unwrap_or(false)
    }

    fn borrow_mut_or_false<F>(&'static self, f: F) -> bool
    where
        F: FnOnce(&mut T) -> bool,
    {
        self.try_with_borrow_mut(f).unwrap_or(false)
    }
}

impl<T> RefCellExt<T> for LocalKey<RefCell<T>> {
    fn try_with_borrow<F, R>(&'static self, f: F) -> Result<R, RefCellExtError>
    where
        F: FnOnce(&T) -> R,
    {
        Ok(self.try_with(|cell| -> Result<R, BorrowError> {
            cell.try_borrow().map(|t| f(t.deref()))
        })??)
    }

    fn try_with_borrow_mut<F, R>(&'static self, f: F) -> Result<R, RefCellExtError>
    where
        F: FnOnce(&mut T) -> R,
    {
        Ok(self.try_with(|cell| -> Result<R, BorrowMutError> {
            cell.try_borrow_mut().map(|mut t| f(t.deref_mut()))
        })??)
    }
}

thread_local! {
    static CLOCKS: RefCell<Clocks> = RefCell::new(Clocks {
        cpu_time: None,
        wall_time: Instant::now(),
    });

    static REQUEST_LOCALS: RefCell<RequestLocals> = RefCell::new(RequestLocals::default());

    /// The tags for this thread/request. These get sent to other threads,
    /// which is why they are Arc. However, they are wrapped in a RefCell
    /// because the values _can_ change from request to request depending on
    /// the values sent in the SAPI for env, service, version, etc. They get
    /// reset at the end of the request.
    static TAGS: RefCell<Arc<Vec<Tag>>> = RefCell::new(Arc::new(Vec::new()));
}

/// Gets the runtime-id for the process. Do not call before RINIT!
fn runtime_id() -> &'static Uuid {
    RUNTIME_ID.get_or_init(|| {
        capi::ddtrace_runtime_id_ptr()
            .and_then(|p| unsafe { p.as_ref() })
            .map_or_else(Uuid::new_v4, |u| *u)
    })
}

extern "C" fn activate() -> i32 {
    // SAFETY: calling in activate as required.
    unsafe { profiling::stack_walking::activate() };
    0
}

/// The mut here is *only* for resetting this back to uninitialized each minit.
static mut ZAI_CONFIG_ONCE: Once = Once::new();
/// The mut here is *only* for resetting this back to uninitialized each minit.
static mut RINIT_ONCE: Once = Once::new();

#[cfg(feature = "tracing")]
thread_local! {
    static REQUEST_SPAN: RefCell<Option<tracing::span::EnteredSpan>> = const {
        RefCell::new(None)
    };
}

// If Failure is returned, the VM will do a C exit. Try hard to avoid that,
// using it for catastrophic errors only.
extern "C" fn rinit(_type: c_int, _module_number: c_int) -> ZendResult {
    #[cfg(feature = "tracing")]
    REQUEST_SPAN.set(Some(tracing::info_span!("request").entered()));

    #[cfg(feature = "tracing")]
    let _rinit_span = tracing::info_span!("rinit").entered();

    #[cfg(debug_assertions)]
    trace!("RINIT({_type}, {_module_number})");

    // SAFETY: not being mutated during rinit.
    let once = unsafe { &*ptr::addr_of!(ZAI_CONFIG_ONCE) };
    once.call_once(|| unsafe {
        bindings::ddog_php_prof_config_first_rinit();
        config::first_rinit();
    });

    bindings::ddog_php_prof_config_rinit();

    // Needs to come after config::first_rinit, because that's what sets the
    // values to the ones in the configuration.
    let system_settings = SystemSettings::get();

    // Universal-only: vm_interrupt_addr validated at load; guaranteed non-null here.
    // SAFETY: rinit is within GINIT–GSHUTDOWN.
    let vm_interrupt_addr = universal::profiling_vm_interrupt_addr(unsafe { OnPhpThread::new() });

    // initialize the thread local storage and cache some items
    let result = REQUEST_LOCALS.try_with_borrow_mut(|locals| {
        locals.vm_interrupt_addr = vm_interrupt_addr;

        // SAFETY: We are after first rinit and before mshutdown.
        unsafe {
            locals.env = config::env();
            locals.service = config::service().or_else(|| {
                match *SAPI {
                    Sapi::Cli => {
                        // SAFETY: sapi globals are safe to access during rinit
                        SAPI.request_script_name(get_sapi_request_info())
                            .map(Cow::into_owned)
                            .or(Some(String::from("cli.command")))
                    }
                    _ => Some(String::from("web.request")),
                }
            });
            locals.version = config::version();
            locals.git_commit_sha = config::git_commit_sha();
            locals.git_repository_url = config::git_repository_url().map(|val| {
                // Remove potential credentials, customers are encouraged to not send those anyway.
                if let Some(at_pos) = val.find("@") {
                    if let Some(proto_pos) = val.find("://") {
                        // Keep protocol, but remove credentials
                        format!("{}{}", &val[..(proto_pos + 3)], &val[(at_pos + 1)..])
                    } else {
                        // No protocol, just remove everything before @
                        val[(at_pos + 1)..].to_string()
                    }
                } else {
                    val
                }
            });

            let (tags, maybe_err) = config::tags();
            if let Some(err) = maybe_err {
                // DD_TAGS can change on each request, so this warns on every
                // request. Maybe we should cache the error string and only
                // emit warnings for new ones?
                warn!("{err}");
            }
            locals.tags = tags;
        }
        locals.system_settings = system_settings;
    });

    if let Err(err) = result {
        error!("failed to borrow request locals in rinit: {err}");
        return ZendResult::Failure;
    }

    // Preloading happens before zend_post_startup_cb is called for the first
    // time. When preloading is enabled and a non-root user is used for
    // php-fpm, there is fork that happens. In the past, having the profiler
    // enabled at this time would cause php-fpm eventually hang once the
    // Profiler's channels were full; this has been fixed. See:
    // https://github.com/DataDog/dd-trace-php/issues/1919
    //
    // When preloading, defer until post_startup. When not preloading, this is a no-op.
    if !is_post_startup() {
        debug!("zend_post_startup_cb hasn't happened yet; not enabling profiler.");
        return ZendResult::Success;
    }

    // SAFETY: safe to dereference in rinit after first_rinit. It's important
    // that this is a non-mut reference because in ZTS there's nothing which
    // enforces mutual exclusion.
    let system_settings = unsafe { system_settings.as_ref() };

    // SAFETY: the once control is not mutable during request.
    let once = unsafe { &*ptr::addr_of!(RINIT_ONCE) };
    once.call_once(|| {
        if system_settings.profiling_enabled {
            // SAFETY: sapi_module is initialized by rinit and shouldn't be
            // modified at this point (safe to read values).
            let sapi_module = unsafe { &*ptr::addr_of!(zend::sapi_module) };
            if sapi_module.pretty_name.is_null() {
                // SAFETY: I'm willing to bet the module name is less than `isize::MAX`.
                let name = unsafe { CStr::from_ptr(sapi_module.name) }.to_string_lossy();
                warn!("The SAPI module {name}'s pretty name was not set!")
            } else {
                // SAFETY: I'm willing to bet the module pretty name is less than `isize::MAX`.
                let pretty_name =
                    unsafe { CStr::from_ptr(sapi_module.pretty_name) }.to_string_lossy();
                if *SAPI != Sapi::Unknown {
                    debug!("Recognized SAPI: {pretty_name}.");
                } else {
                    warn!("Unrecognized SAPI: {pretty_name}.");
                }
            }
            if let Err(err) = cpu_time::ThreadTime::try_now() {
                if system_settings.profiling_experimental_cpu_time_enabled {
                    warn!("CPU Time collection was enabled but collection failed: {err}");
                } else {
                    debug!("CPU Time collection was not enabled and isn't available: {err}");
                }
            } else if system_settings.profiling_experimental_cpu_time_enabled {
                info!("CPU Time profiling enabled.");
            }
        }

        exception::exception_profiling_first_rinit();

        #[cfg(all(feature = "io_profiling", target_os = "linux"))]
        io::io_prof_first_rinit();

        allocation::first_rinit(system_settings);
    });

    Profiler::init(system_settings);

    if system_settings.profiling_enabled {
        // Not logging, rinit could be quite spammy.
        _ = REQUEST_LOCALS.try_with_borrow(|locals| {
            let cpu_time_enabled = system_settings.profiling_experimental_cpu_time_enabled;
            let wall_time_enabled = system_settings.profiling_wall_time_enabled;
            CLOCKS.with_borrow_mut(|clocks| clocks.initialize(cpu_time_enabled));

            TAGS.set({
                // SAFETY: accessing in RINIT after config is initialized.
                let globals = GLOBAL_TAGS.deref();
                let extra_tags_len = locals.service.is_some() as usize
                    + locals.env.is_some() as usize
                    + locals.version.is_some() as usize
                    + locals.git_commit_sha.is_some() as usize
                    + locals.git_repository_url.is_some() as usize;

                let mut tags = Vec::new();
                tags.reserve_exact(globals.len() + extra_tags_len + locals.tags.len());
                tags.extend_from_slice(globals.as_slice());
                add_optional_tag(&mut tags, "service", &locals.service);
                add_optional_tag(&mut tags, "env", &locals.env);
                add_optional_tag(&mut tags, "version", &locals.version);
                add_optional_tag(&mut tags, "git.commit.sha", &locals.git_commit_sha);
                add_optional_tag(&mut tags, "git.repository_url", &locals.git_repository_url);
                tags.extend_from_slice(locals.tags.as_slice());
                Arc::new(tags)
            });

            // Only add interrupt if cpu- or wall-time is enabled.
            if !(cpu_time_enabled | wall_time_enabled) {
                return;
            }

            if let Some(profiler) = Profiler::get() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count as *const AtomicU32,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                profiler.add_interrupt(interrupt);
            }
        });
    } else {
        TAGS.set(Arc::default());
    }

    // SAFETY: called exactly once per RINIT on a PHP thread within GINIT–GSHUTDOWN.
    unsafe { allocation::rinit(OnPhpThread::new()) };

    // SAFETY: called after config is initialized.
    unsafe { timeline::timeline_rinit() };

    ZendResult::Success
}

fn add_optional_tag<T: AsRef<str>>(tags: &mut Vec<Tag>, key: &str, value: &Option<T>) {
    if let Some(value) = value {
        add_tag(tags, key, value.as_ref());
    }
}

fn add_tag(tags: &mut Vec<Tag>, key: &str, value: &str) {
    assert!(!value.is_empty());
    match Tag::new(key, value) {
        Ok(tag) => tags.push(tag),
        Err(err) => warn!("invalid {key} tag: {err}"),
    }
}

extern "C" fn rshutdown(_type: c_int, _module_number: c_int) -> ZendResult {
    #[cfg(feature = "tracing")]
    let _rshutdown_span = tracing::info_span!("rshutdown").entered();

    // todo: merge these lifecycle things to tracing feature?
    #[cfg(debug_assertions)]
    trace!("RSHUTDOWN({_type}, {_module_number})");

    if !is_post_startup() {
        return ZendResult::Success;
    }

    profiling::stack_walking::rshutdown();

    // Not logging, rshutdown could be quite spammy.
    _ = REQUEST_LOCALS.try_with_borrow(|locals| {
        let system_settings = locals.system_settings();

        // The interrupt is only added if CPU- or wall-time are enabled BUT
        // wall-time is not expected to ever be disabled, except in testing,
        // and we don't need to optimize for that.
        if system_settings.profiling_enabled {
            if let Some(profiler) = Profiler::get() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                profiler.remove_interrupt(interrupt);
            }
        }
    });

    // SAFETY: called exactly once per RSHUTDOWN on a PHP thread within GINIT–GSHUTDOWN.
    unsafe { allocation::alloc_prof_rshutdown(OnPhpThread::new()) };

    #[cfg(feature = "tracing")]
    REQUEST_SPAN.take();

    ZendResult::Success
}

/// Prints the module info. Calls many C functions from the Zend Engine,
/// including calling variadic functions. It's essentially all unsafe, so be
/// careful, and do not call this manually (only let the engine call it).
unsafe extern "C" fn minfo(module_ptr: *mut zend::ModuleEntry) {
    // todo: merge these lifecycle things to tracing feature?
    #[cfg(debug_assertions)]
    trace!("MINFO({:p})", module_ptr);

    let module = &*module_ptr;

    let result = REQUEST_LOCALS.try_with_borrow(|locals| {
        let system_settings = locals.system_settings();
        let yes = c"true".as_ptr();
        let yes_exp = c"true (all experimental features enabled)".as_ptr();
        let no = c"false".as_ptr();
        let no_all = c"false (profiling disabled)".as_ptr();
        zend::php_info_print_table_start();
        zend::php_info_print_table_row(2, c"Version".as_ptr(), module.version.cast::<c_char>());
        zend::php_info_print_table_row(
            2,
            c"Profiling Enabled".as_ptr(),
            if system_settings.profiling_enabled { yes } else { no },
        );

        zend::php_info_print_table_row(
            2,
            c"Profiling Experimental Features Enabled".as_ptr(),
            if system_settings.profiling_experimental_features_enabled {
                yes
            } else if system_settings.profiling_enabled {
                no
            } else {
                no_all
            },
        );

        zend::php_info_print_table_row(
            2,
            c"Experimental CPU Time Profiling Enabled".as_ptr(),
            if system_settings.profiling_experimental_cpu_time_enabled {
                if system_settings.profiling_experimental_features_enabled {
                    yes_exp
                } else {
                    yes
                }
            } else if system_settings.profiling_enabled {
                no
            } else {
                no_all
            },
        );

                zend::php_info_print_table_row(
                    2,
                    c"Allocation Profiling Enabled".as_ptr(),
                    if system_settings.profiling_allocation_enabled {
                        yes
                    } else if crate::allocation::jit_enabled() {
                        // Work around version-specific issues.
                        if !universal::has_zend_mm_set_custom_handlers_ex() {
                            c"Not available due to JIT being active, see https://github.com/DataDog/dd-trace-php/pull/2088 for more information.".as_ptr()
                        } else {
                            c"Not available due to JIT being active, see https://github.com/DataDog/dd-trace-php/pull/3199 for more information.".as_ptr()
                        }
                    } else if system_settings.profiling_enabled {
                        no
                    } else {
                        no_all
                    }
                );
                zend::php_info_print_table_row(
                    2,
                    c"Timeline Enabled".as_ptr(),
                    if system_settings.profiling_timeline_enabled {
                        yes
                    } else if system_settings.profiling_enabled {
                        no
                    } else {
                        no_all
                    },
                );

                zend::php_info_print_table_row(
                    2,
                    c"Exception Profiling Enabled".as_ptr(),
                    if system_settings.profiling_exception_enabled {
                        yes
                    } else if system_settings.profiling_enabled {
                        no
                    } else {
                        no_all
                    },
                );


        cfg_if::cfg_if! {
            if #[cfg(feature = "io_profiling")] {
                zend::php_info_print_table_row(
                    2,
                    c"I/O Profiling Enabled".as_ptr(),
                    if system_settings.profiling_io_enabled {
                        yes
                    } else if system_settings.profiling_enabled {
                        no
                    } else {
                        no_all
                    },
                );
            } else {
                zend::php_info_print_table_row(
                    2,
                    c"I/O Profiling Enabled".as_ptr(),
                    c"Not available. The profiler was built without I/O profiling support.".as_ptr()
                );
            }
        }

        zend::php_info_print_table_row(
            2,
            c"Endpoint Collection Enabled".as_ptr(),
            if system_settings.profiling_endpoint_collection_enabled {
                yes
            } else if system_settings.profiling_enabled {
                no
            } else {
                no_all
            },
        );

        zend::php_info_print_table_row(
            2,
            c"Platform's CPU Time API Works".as_ptr(),
            if cpu_time::ThreadTime::try_now().is_ok() {
                yes
            } else {
                no
            },
        );

        let printable_log_level = if system_settings.profiling_enabled {
            let mut log_level = format!("{}\0", system_settings.profiling_log_level);
            log_level.make_ascii_lowercase();
            Cow::from(log_level)
        } else {
            Cow::from(String::from("off (profiling disabled)\0"))
        };

        zend::php_info_print_table_row(
            2,
            c"Profiling Log Level".as_ptr(),
            printable_log_level.as_ptr().cast::<c_char>()
        );

        let key = c"Profiling Agent Endpoint".as_ptr();
        let agent_endpoint = format!("{}\0", system_settings.uri);
        zend::php_info_print_table_row(2, key, agent_endpoint.as_ptr().cast::<c_char>());

        let vars = [
            (c"Application's Environment (DD_ENV)".as_ptr(), &locals.env),
            (c"Application's Service (DD_SERVICE)".as_ptr(), &locals.service),
            (c"Application's Version (DD_VERSION)".as_ptr(), &locals.version),
        ];

        for (key, value) in vars {
            let mut value = match value {
                Some(string) => string.clone(),
                None => String::new(),
            };
            value.push('\0');
            zend::php_info_print_table_row(2, key, value.as_ptr().cast::<c_char>());
        }

        zend::php_info_print_table_end();

        zend::display_ini_entries(module_ptr);
    });

    if let Err(err) = result {
        error!("minfo failed to borrow request locals: {err}");
    }
}

extern "C" fn mshutdown(_type: c_int, _module_number: c_int) -> ZendResult {
    // todo: merge these lifecycle things to tracing feature?
    #[cfg(debug_assertions)]
    trace!("MSHUTDOWN({_type}, {_module_number})");

    // SAFETY: being called before [config::shutdown].
    timeline::timeline_mshutdown();

    exception::exception_profiling_mshutdown();

    // SAFETY: calling in mshutdown as required.
    unsafe { Profiler::stop(Duration::from_secs(1)) };

    ZendResult::Success
}

extern "C" fn startup(extension: *mut ZendExtension) -> ZendResult {
    eprintln!("[ddog-prof] startup({:p}) enter", extension);

    // todo: merge these lifecycle things to tracing feature?
    #[cfg(debug_assertions)]
    trace!("startup({:p})", extension);

    // Handle ownership: the zend_extension must own the dlopen handle.
    // extension= path: module owns handle, we transfer from module to extension.
    // zend_extension= path: extension already has handle from engine.
    if !extension.is_null() && unsafe { (*extension).handle.is_null() } {
        let module_ptr = find_module_entry(PROFILER_NAME);
        if !module_ptr.is_null() {
            let module = unsafe { &mut *module_ptr };
            unsafe {
                (*extension).handle = module.handle;
                module.handle = ptr::null_mut();
            }
        }
    }

    zend::datadog_php_profiling_startup(extension);

    if crate::matrix_entry().has_run_time_cache() {
        bindings::ddog_php_prof_function_run_time_cache_init(PROFILER_NAME.as_ptr());
    }

    // SAFETY: calling this in zend_extension startup.
    unsafe {
        pthread::startup();
        timeline::timeline_startup();
    }

    post_startup_init();
    allocation::alloc_prof_startup();

    ZendResult::Success
}

extern "C" fn shutdown(extension: *mut ZendExtension) {
    #[cfg(feature = "tracing")]
    let _shutdown_span = tracing::info_span!("shutdown").entered();

    // todo: merge these lifecycle things to tracing feature?
    #[cfg(debug_assertions)]
    trace!("shutdown({:p})", extension);

    // If a timeout was reached, then the thread is possibly alive.
    // This means the engine cannot unload our handle, or else we'd
    // immediately hit undefined behavior (and likely crash).
    // SAFETY: calling in Zend Extension shutdown as required.
    if let Err(err) = unsafe { Profiler::shutdown(Duration::from_secs(2)) } {
        let num_failures = err.num_failures;
        error!("{num_failures} thread(s) failed to join, intentionally leaking the extension's handle to prevent unloading");
        // SAFETY: during mshutdown, we have ownership of the extension struct.
        // Our threads (which failed to join) do not mutate this struct at all
        // either, providing no races.
        unsafe { (*extension).handle = ptr::null_mut() }
    }

    // SAFETY: calling in shutdown before zai config is shutdown, and after
    // all configuration is done being accessed. Well... in the happy-path,
    // anyway. If the join with the uploader times out, there could become a
    // data race condition.
    unsafe { config::shutdown() };

    // SAFETY: ddog_php_prof_config_mshutdown should be safe to call in shutdown instead
    // of mshutdown.
    bindings::ddog_php_prof_config_mshutdown();
}

/// Notifies the profiler a trace has finished so it can update information
/// for Endpoint Profiling.
fn notify_trace_finished(local_root_span_id: u64, span_type: Cow<str>, resource: Cow<str>) {
    let result = REQUEST_LOCALS.try_with_borrow(|locals| {
        let system_settings = locals.system_settings();
        if system_settings.profiling_enabled && system_settings.profiling_endpoint_collection_enabled {
            // Only gather Endpoint Profiling data for web spans, partly for PII reasons.
            if span_type != "web" {
                debug!(
                    "Local root span id {local_root_span_id} ended but did not have a span type of 'web' (actual: '{span_type}'), so Endpoint Profiling data will not be sent."
                );
                return;
            }

            if let Some(profiler) = Profiler::get() {
                let message = LocalRootSpanResourceMessage {
                    local_root_span_id,
                    resource: resource.into_owned(),
                };
                if let Err(err) = profiler.send_local_root_span_resource(message) {
                    warn!("Failed to enqueue endpoint profiling information: {err}.");
                } else {
                    trace!(
                        "Enqueued endpoint profiling information for span id: {local_root_span_id}."
                    );
                }
            }
        }
    });

    if let Err(err) = result {
        debug!("tracer failed to notify profiler about a finished trace because the request locals could not be borrowed: {err}");
    }
}

/// Looks up a module by name in PHP's module_registry.
///
/// Works across PHP 7.1–8.5 via runtime symbol resolution which means it's not
/// meant to be called in hot paths.
pub fn find_module_entry(name: &CStr) -> *mut zend::ModuleEntry {
    let hash_find_sym = universal::runtime::symbol_addr("zend_hash_str_find");
    let module_registry_sym = universal::runtime::symbol_addr("module_registry");
    if hash_find_sym.is_null() || module_registry_sym.is_null() {
        return ptr::null_mut();
    }
    type ZendHashStrFindFn =
        unsafe extern "C" fn(*const core::ffi::c_void, *const c_char, usize) -> *mut zend::zval;
    let hash_find: ZendHashStrFindFn = unsafe { core::mem::transmute(hash_find_sym) };
    let zv = unsafe { hash_find(module_registry_sym, name.as_ptr(), name.to_bytes().len()) };
    if zv.is_null() {
        return ptr::null_mut();
    }
    unsafe { (*zv).value.ptr as *mut zend::ModuleEntry }
}
