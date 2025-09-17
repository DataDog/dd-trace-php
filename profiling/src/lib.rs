pub mod bindings;
pub mod bitset;
pub mod capi;
pub mod inlinevec;
pub mod profiling;

mod clocks;
mod config;
mod logging;
mod pthread;
mod sapi;
mod thin_str;
mod vec_ext;
mod wall_time;

#[cfg(php_run_time_cache)]
mod string_set;

#[cfg(feature = "allocation_profiling")]
mod allocation;

#[cfg(all(feature = "io_profiling", target_os = "linux"))]
mod io;

#[cfg(feature = "exception_profiling")]
mod exception;

#[cfg(feature = "timeline")]
mod timeline;

use crate::config::{SystemSettings, INITIAL_SYSTEM_SETTINGS};
use crate::inlinevec::InlineVec;
use crate::zend::datadog_sapi_globals_request_info;
use bindings::{
    self as zend, ddog_php_prof_php_version, ddog_php_prof_php_version_id, ZendExtension,
    ZendResult,
};
use clocks::*;
use core::ffi::{c_char, c_int, c_void, CStr};
use core::ptr;
use ddcommon::{cstr, tag, tag::Tag};
use lazy_static::lazy_static;
use log::{debug, error, info, trace, warn};
use once_cell::sync::{Lazy, OnceCell};
use profiling::{LocalRootSpanResourceMessage, Profiler, VmInterrupt};
use sapi::Sapi;
use std::borrow::Cow;
use std::cell::{BorrowError, BorrowMutError, RefCell};
use std::ops::{Deref, DerefMut};
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Once};
use std::thread::{AccessError, LocalKey};
use std::time::{Duration, Instant};
use uuid::Uuid;

/// Name of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_NAME: &CStr = c"datadog-profiling";

/// Version of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_VERSION: &[u8] = concat!(env!("PROFILER_VERSION"), "\0").as_bytes();

/// Version ID of PHP at run-time, not the version it was built against at
/// compile-time. Its value is overwritten during minit.
static RUNTIME_PHP_VERSION_ID: AtomicU32 = AtomicU32::new(zend::PHP_VERSION_ID);

/// Version str of PHP at run-time, not the version it was built against at
/// compile-time. Its value is overwritten during minit, unless there are
/// errors at run-time, and then the compile-time value will still remain.
static mut RUNTIME_PHP_VERSION: &str = {
    // This takes a weird path in order to be const.
    let ptr: *const u8 = zend::PHP_VERSION.as_ptr();
    let len = zend::PHP_VERSION.len() - 1;
    let bytes = unsafe { core::slice::from_raw_parts(ptr, len) };
    match core::str::from_utf8(bytes) {
        Ok(str) => str,
        Err(_) => panic!("PHP_VERSION string was not valid UTF-8"),
    }
};

lazy_static! {
    // Do not call until RINIT!
    static ref GLOBAL_TAGS: [Tag; 7] = [
        tag!("language", "php"),
        // This should probably be "language_version", but this is the
        // standardized tag name.
        // SAFETY: safe to access in rinit (mutated only in minit).
        Tag::new("runtime_version", unsafe { RUNTIME_PHP_VERSION }).expect("runtime-version tag to be valid"),
        tag!("profiler_version", env!("PROFILER_VERSION")),
        // In case we ever add PHP debug build support, we should add
        // `zend-zts-debug` and `zend-nts-debug`. For the time being we only
        // support `zend-zts-ndebug` and `zend-nts-ndebug`.
        tag!(
            "runtime_engine",
            if cfg!(php_zts) {
                "zend-zts-ndebug"
            } else {
                "zend-nts-ndebug"
            }
        ),
        Tag::new("php.sapi", SAPI.as_ref()).expect("php.sapi tag to be valid"),
        // SAFETY: calling getpid() is safe.
        Tag::new("process_id", unsafe { libc::getpid() }.to_string())
            .expect("process_id tag to be valid"),
        Tag::new("runtime-id", runtime_id().to_string()).expect("runtime-id tag to be valid"),

    ];

    /// The Server API the profiler is running under.
    static ref SAPI: Sapi = {
        #[cfg(not(test))]
        {
            // SAFETY: sapi_module is initialized before minit and there should be
            // no concurrent threads.
            let sapi_module = unsafe { zend::sapi_module };
            if sapi_module.name.is_null() {
                panic!("the sapi_module's name is a null pointer");
            }

            // SAFETY: value has been checked for NULL; I haven't checked that the
            // engine ensures its length is less than `isize::MAX`, but it is a
            // risk I'm willing to take.
            let sapi_name = unsafe { CStr::from_ptr(sapi_module.name) };
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
static RUNTIME_ID: OnceCell<Uuid> = OnceCell::new();
// If ddtrace is loaded, we fetch the uuid from there instead
extern "C" {
    pub static ddtrace_runtime_id: *const Uuid;
}

/// We do not have any globals, but we need TSRM to call into GINIT and GSHUTDOWN to observe
/// spawning and joining threads. This will be pointed to by the [`ModuleEntry::globals_id_ptr`] in
/// the `zend_module_entry` and the TSRM will store it's thread-safe-resource id here.
/// See: <https://heap.space/xref/PHP-8.3/Zend/zend_API.c?r=d41e97ae#2303>
#[cfg(php_zts)]
static mut GLOBALS_ID_PTR: i32 = 0;

/// The function `get_module` is what makes this a PHP module. Please do not
/// call this directly; only let it be called by the engine. Generally it is
/// only called once, but if someone accidentally loads the module twice then
/// it might get called more than once, though it will warn and not use the
/// consecutive return value.
#[no_mangle]
pub extern "C" fn get_module() -> &'static mut zend::ModuleEntry {
    static DEPS: [zend::ModuleDep; 8] = [
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

    // In PHP modules written in C, this just returns the address of a global,
    // mutable variable. In Rust, you cannot initialize such a complicated
    // global variable because of initialization order issues that have been
    // found through decades of C++ experience.
    // There are a variety of ways to deal with this; this is just one way.
    static mut MODULE: Lazy<zend::ModuleEntry> = Lazy::new(|| zend::ModuleEntry {
        name: PROFILER_NAME.as_ptr(),
        // SAFETY: php_ffi.c defines this correctly
        functions: unsafe { bindings::ddog_php_prof_functions },
        module_startup_func: Some(minit),
        module_shutdown_func: Some(mshutdown),
        request_startup_func: Some(rinit),
        request_shutdown_func: Some(rshutdown),
        info_func: Some(minfo),
        version: PROFILER_VERSION.as_ptr(),
        post_deactivate_func: Some(prshutdown),
        deps: DEPS.as_ptr(),
        globals_ctor: Some(ginit),
        globals_dtor: Some(gshutdown),
        globals_size: 1,
        #[cfg(php_zts)]
        globals_id_ptr: unsafe { ptr::addr_of_mut!(GLOBALS_ID_PTR) },
        #[cfg(not(php_zts))]
        globals_ptr: ptr::null_mut(),
        ..Default::default()
    });

    // SAFETY: well, it's at least as safe as what every single C extension does.
    unsafe { &mut *ptr::addr_of_mut!(MODULE) }
}

unsafe extern "C" fn ginit(_globals_ptr: *mut c_void) {
    #[cfg(all(feature = "timeline", php_zts))]
    timeline::timeline_ginit();

    #[cfg(feature = "allocation_profiling")]
    allocation::alloc_prof_ginit();
}

unsafe extern "C" fn gshutdown(_globals_ptr: *mut c_void) {
    #[cfg(all(feature = "timeline", php_zts))]
    timeline::timeline_gshutdown();

    #[cfg(feature = "allocation_profiling")]
    allocation::alloc_prof_gshutdown();
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

    // Update the runtime PHP_VERSION and PHP_VERSION_ID.
    {
        // SAFETY: safe to call any time in a module because the engine is
        // initialized before modules are ever loaded.
        let php_version_id = unsafe { ddog_php_prof_php_version_id() };
        RUNTIME_PHP_VERSION_ID.store(php_version_id, Ordering::Relaxed);

        // SAFETY: calling zero-arg fn that is safe to call in minit.
        let ptr = unsafe { ddog_php_prof_php_version() };
        // SAFETY: the version str is always in static memory, either
        // PHP_VERSION or the Reflection module version.
        let cstr: &'static CStr = unsafe { CStr::from_ptr(ptr) };
        match cstr.to_str() {
            Ok(str) => unsafe { RUNTIME_PHP_VERSION = str },
            Err(err) => warn!("failed to detect PHP_VERSION at runtime: {err}"),
        };
    }

    config::minit(module_number);

    // Use a hybrid extension hack to load as a module but have the
    // zend_extension hooks available:
    // https://www.phpinternalsbook.com/php7/extensions_design/zend_extensions.html#hybrid-extensions
    // In this case, use the same technique as the tracer: transfer the module
    // handle to the zend_extension as extensions have longer lifetimes than
    // modules in the engine.
    let handle = {
        // Levi modified the engine for PHP 8.2 to stop copying the module:
        // https://github.com/php/php-src/pull/8551
        // Before then, the engine copied the module entry we provided. We
        // find the module entry in the registry and modify it there instead
        // of just modifying the result of get_module().
        let str = PROFILER_NAME.as_ptr();
        let len = PROFILER_NAME_STR.len();

        // SAFETY: str is valid for at least len values.
        let ptr = unsafe { zend::datadog_get_module_entry(str, len) };
        if ptr.is_null() {
            error!("Unable to locate our own module in the engine registry.");
            return ZendResult::Failure;
        }

        // SAFETY: `ptr` was checked for nullability already. Transferring the
        // handle from the module to the extension extends the lifetime, not
        // shortens it, so it's safe. But of course, be sure the code below
        // actually passes it to the extension.
        unsafe {
            let module = &mut *ptr;
            let handle = module.handle;
            module.handle = ptr::null_mut();
            handle
        }
    };

    // Currently, the engine is always copying this struct into a
    // zend_llist_element. Every time a new PHP version is released, we should
    // double-check zend_register_extension to ensure the address is not
    // mutated nor stored. Well, hopefully we catch it _before_ a release.
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

    // SAFETY: during minit there shouldn't be any threads to race against these writes.
    unsafe { wall_time::minit() };

    // SAFETY: all arguments are valid for this C call.
    // Note that on PHP 7 this never fails, and on PHP 8 it returns void.
    unsafe { zend::zend_register_extension(&extension, handle) };

    #[cfg(feature = "timeline")]
    timeline::timeline_minit();

    #[cfg(feature = "exception_profiling")]
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
    unsafe { bindings::zai_config_rshutdown() };

    #[cfg(feature = "timeline")]
    timeline::timeline_prshutdown();

    ZendResult::Success
}

pub struct RequestLocals {
    pub env: Option<String>,
    pub service: Option<String>,
    pub version: Option<String>,
    pub tags: Vec<Tag>,

    /// SystemSettings are global. Note that if this is being read in fringe
    /// conditions such as in mshutdown when there were no requests served,
    /// then the settings are still memory safe, but they may not have the
    /// real configuration. Instead, they have a best-effort values such as
    /// INITIAL_SYSTEM_SETTINGS, or possibly the values which were available
    /// in MINIT.
    pub system_settings: ptr::NonNull<SystemSettings>,

    pub interrupt_count: AtomicU32,
    pub vm_interrupt_addr: *const AtomicBool,
}

impl RequestLocals {
    #[track_caller]
    pub fn system_settings(&self) -> &SystemSettings {
        // SAFETY: it should always be valid, either set to the
        // INITIAL_SYSTEM_SETTINGS or to the SYSTEM_SETTINGS.
        unsafe { self.system_settings.as_ref() }
    }
}

impl Default for RequestLocals {
    fn default() -> RequestLocals {
        RequestLocals {
            env: None,
            service: None,
            version: None,
            tags: vec![],
            system_settings: ptr::NonNull::from(INITIAL_SYSTEM_SETTINGS.deref()),
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
    RUNTIME_ID
        .get_or_init(|| unsafe { ddtrace_runtime_id.as_ref() }.map_or_else(Uuid::new_v4, |u| *u))
}

extern "C" fn activate() {
    // SAFETY: calling in activate as required.
    unsafe { profiling::stack_walking::activate() };
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
        bindings::zai_config_first_time_rinit(true);
        config::first_rinit();
    });

    unsafe { bindings::zai_config_rinit() };

    // SAFETY: We are after first rinit and before config mshutdown.
    let mut system_settings = unsafe { SystemSettings::get() };

    // initialize the thread local storage and cache some items
    let result = REQUEST_LOCALS.try_with_borrow_mut(|locals| {
        // SAFETY: we are in rinit on a PHP thread.
        locals.vm_interrupt_addr = unsafe { zend::datadog_php_profiling_vm_interrupt_addr() };
        locals.interrupt_count.store(0, Ordering::SeqCst);

        // SAFETY: We are after first rinit and before mshutdown.
        unsafe {
            locals.env = config::env();
            locals.service = config::service().or_else(|| {
                match *SAPI {
                    Sapi::Cli => {
                        // SAFETY: sapi globals are safe to access during rinit
                        SAPI.request_script_name(datadog_sapi_globals_request_info())
                            .map(Cow::into_owned)
                            .or(Some(String::from("cli.command")))
                    }
                    _ => Some(String::from("web.request")),
                }
            });
            locals.version = config::version();

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
    // There are a few ways to handle this preloading scenario with the fork,
    // but the  simplest is to not enable the profiler until the engine's
    // startup is complete. This means the preloading will not be profiled,
    // but this should be okay.
    #[cfg(php_preload)]
    if !unsafe { bindings::ddog_php_prof_is_post_startup() } {
        debug!("zend_post_startup_cb hasn't happened yet; not enabling profiler.");
        return ZendResult::Success;
    }

    // SAFETY: still safe to access in rinit after first_rinit.
    let system_settings = unsafe { system_settings.as_mut() };

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

        #[cfg(feature = "exception_profiling")]
        exception::exception_profiling_first_rinit();

        #[cfg(all(feature = "io_profiling", target_os = "linux"))]
        io::io_prof_first_rinit();

        #[cfg(feature = "allocation_profiling")]
        allocation::alloc_prof_first_rinit();
    });

    Profiler::init(system_settings);

    if system_settings.profiling_enabled {
        // Not logging, rinit could be quite spammy.
        _ = REQUEST_LOCALS.try_with_borrow(|locals| {
            let cpu_time_enabled = system_settings.profiling_experimental_cpu_time_enabled;
            let wall_time_enabled = system_settings.profiling_wall_time_enabled;
            CLOCKS.with_borrow_mut(|clocks| clocks.initialize(cpu_time_enabled));

            // If the tags can't be borrowed, they'll just stay the same.
            _ = TAGS.try_with_borrow_mut(|old_tags| {
                replace_tags(old_tags, GLOBAL_TAGS.as_slice(), locals)
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

    #[cfg(feature = "allocation_profiling")]
    allocation::alloc_prof_rinit();

    // SAFETY: called after config is initialized.
    #[cfg(feature = "timeline")]
    unsafe {
        timeline::timeline_rinit()
    };

    ZendResult::Success
}

/// Replaces the current tags if the unified service tags or DD_TAGS have
/// changed from the last request on this thread. Always replaces on the first
/// request per thread.
fn replace_tags(old_tags: &mut Arc<Vec<Tag>>, globals: &[Tag], locals: &RequestLocals) {
    // Try and be smart and only recreate tags if locals.tags (aka DD_TAGS) or
    // unified service tags have changed. This relies on the implementation
    // always adding tags in the same order:
    //  1. Globals e.g. process id, runtime id, language, lang version, etc.
    //  2. Unified service tags e.g. DD_SERIVCE, DD_ENV, DD_VERSION.
    //  3. Locals e.g. DD_TAGS.
    // Note that I'd prefer a different order (globals, locals, unified) but
    // this might change the precedence of DD_TAGS and DD_SERVICE if someone
    // sets both e.g. DD_TAGS="service:foo" DD_SERVICE="bar". I haven't done
    // the homework to see if that's okay to change, so I'm not changing it.
    let mut unified: InlineVec<Tag, 3> = InlineVec::new();
    add_optional_tag(&mut unified, "service", &locals.service);
    add_optional_tag(&mut unified, "env", &locals.env);
    add_optional_tag(&mut unified, "version", &locals.version);

    let n_tags = globals.len() + unified.len() + locals.tags.len();
    // Do more eq checks only if the tag lengths aren't the same. On the first
    // request, old_tags.len() will be 0, for instance, but if service, env,
    // version, or DD_TAGS change between requests, then we will do more
    // advanced detection.
    if old_tags.len() == n_tags {
        // We can skip globals, those don't change. Start with unified service
        // tags instead.
        let slice = &old_tags[globals.len()..];
        let (middle, end) = slice.split_at(unified.len());
        if middle == unified.as_slice() && end == locals.tags.as_slice() {
            return;
        }
    }

    let mut new_tags = Vec::new();
    new_tags.reserve_exact(n_tags);
    new_tags.extend_from_slice(globals);
    new_tags.extend_from_slice(unified.as_slice());
    new_tags.extend_from_slice(locals.tags.as_slice());

    if let Some(tags) = Arc::get_mut(old_tags) {
        *tags = new_tags;
    } else {
        *old_tags = Arc::new(new_tags);
    }
}

fn add_optional_tag(tags: &mut InlineVec<Tag, 3>, key: &str, value: &Option<String>) {
    if let Some(value) = value {
        assert!(!value.is_empty());
        match Tag::new(key, value) {
            Ok(tag) => {
                if tags.try_push(tag).is_err() {
                    warn!("storage full: couldn't add tag \"{key}:{value}\"");
                }
            }
            Err(err) => {
                warn!("invalid tag: {err}");
            }
        }
    }
}

extern "C" fn rshutdown(_type: c_int, _module_number: c_int) -> ZendResult {
    #[cfg(feature = "tracing")]
    let _rshutdown_span = tracing::info_span!("rshutdown").entered();

    // todo: merge these lifecycle things to tracing feature?
    #[cfg(debug_assertions)]
    trace!("RSHUTDOWN({_type}, {_module_number})");

    #[cfg(php_preload)]
    if !unsafe { bindings::ddog_php_prof_is_post_startup() } {
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

    #[cfg(feature = "allocation_profiling")]
    allocation::alloc_prof_rshutdown();

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
        zend::php_info_print_table_row(2, c"Version".as_ptr(), module.version);
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

        cfg_if::cfg_if! {
            if #[cfg(feature = "allocation_profiling")] {
                zend::php_info_print_table_row(
                    2,
                    c"Allocation Profiling Enabled".as_ptr(),
                    if system_settings.profiling_allocation_enabled {
                        yes
                    } else if zend::ddog_php_jit_enabled() {
                        // Work around version-specific issues.
                        if cfg!(not(php_zend_mm_set_custom_handlers_ex)) {
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
            } else {
                zend::php_info_print_table_row(
                    2,
                    b"Allocation Profiling Enabled\0".as_ptr(),
                    b"Not available. The profiler was built without allocation profiling.\0"
                );
            }
        }

        cfg_if::cfg_if! {
            if #[cfg(feature = "timeline")] {
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
            } else {
                zend::php_info_print_table_row(
                    2,
                    c"Timeline Enabled".as_ptr(),
                    c"Not available. The profiler was build without timeline support.".as_ptr()
                );
            }
        }

        cfg_if::cfg_if! {
            if #[cfg(feature = "exception_profiling")] {
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
            } else {
                zend::php_info_print_table_row(
                    2,
                    c"Exception Profiling Enabled".as_ptr(),
                    c"Not available. The profiler was built without exception profiling support.".as_ptr()
                );
            }
        }

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
        zend::php_info_print_table_row(2, key, agent_endpoint.as_ptr());

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
    #[cfg(feature = "timeline")]
    timeline::timeline_mshutdown();

    #[cfg(feature = "exception_profiling")]
    exception::exception_profiling_mshutdown();

    // SAFETY: calling in mshutdown as required.
    unsafe { Profiler::stop(Duration::from_secs(1)) };

    ZendResult::Success
}

extern "C" fn startup(extension: *mut ZendExtension) -> ZendResult {
    // todo: merge these lifecycle things to tracing feature?
    #[cfg(debug_assertions)]
    trace!("startup({:p})", extension);

    // SAFETY: called during startup hook with correct params.
    unsafe { zend::datadog_php_profiling_startup(extension) };

    #[cfg(php_run_time_cache)]
    // SAFETY: calling this in startup/minit as required.
    unsafe {
        bindings::ddog_php_prof_function_run_time_cache_init(PROFILER_NAME.as_ptr())
    };

    // SAFETY: calling this in zend_extension startup.
    unsafe {
        pthread::startup();
        #[cfg(feature = "timeline")]
        timeline::timeline_startup();
    }

    #[cfg(all(
        feature = "allocation_profiling",
        not(php_zend_mm_set_custom_handlers_ex)
    ))]
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

    // SAFETY: zai_config_mshutdown should be safe to call in shutdown instead
    // of mshutdown.
    unsafe { bindings::zai_config_mshutdown() };
    unsafe { bindings::zai_json_shutdown_bindings() };
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

#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;
    use std::sync::Arc;

    fn make_tag(key: &str, value: &str) -> Tag {
        Tag::new(key, value).expect("tag must be valid")
    }

    fn globals_g1() -> Vec<Tag> {
        vec![make_tag("global", "g1")]
    }

    fn build_locals(service: &str, env: &str, version: &str, tags: Vec<Tag>) -> RequestLocals {
        let mut locals = RequestLocals::default();
        locals.service = Some(service.into());
        locals.env = Some(env.into());
        locals.version = Some(version.into());
        locals.tags = tags;
        locals
    }

    fn expected_tags(globals: &[Tag], locals: &RequestLocals) -> Vec<Tag> {
        let mut expected: Vec<Tag> = Vec::with_capacity(locals.tags.len() + 3);
        expected.extend(globals.iter().cloned());

        if let Some(service) = &locals.service {
            expected.push(make_tag("service", service));
        }
        if let Some(env) = &locals.env {
            expected.push(make_tag("env", env));
        }
        if let Some(version) = &locals.version {
            expected.push(make_tag("version", version));
        }

        expected.extend(locals.tags.iter().cloned());
        expected
    }

    #[test]
    fn replace_tags_uses_new_when_old_empty() {
        let globals = vec![make_tag("global", "g1")];

        let mut locals = RequestLocals::default();
        locals.service = Some("svc".into());
        locals.env = Some("prod".into());
        locals.version = Some("1.2.3".into());
        locals.tags = vec![make_tag("alpha", "beta")];

        let mut arc_tags: Arc<Vec<Tag>> = Arc::new(Vec::new());

        replace_tags(&mut arc_tags, &globals, &locals);

        let expected = expected_tags(&globals, &locals);
        assert_eq!(arc_tags.as_slice(), expected.as_slice());
    }

    #[test]
    fn replace_tags_reuses_when_identical() {
        let globals = vec![make_tag("global", "g1")];

        let mut locals = RequestLocals::default();
        locals.service = Some("svc".into());
        locals.env = Some("prod".into());
        locals.version = Some("1.2.3".into());
        locals.tags = vec![make_tag("alpha", "beta"), make_tag("foo", "bar")];

        let initial = Arc::new(expected_tags(&globals, &locals));
        // Use a distinct Arc with identical contents; replace_tags should detect
        // equality and leave the Arc allocation untouched.
        let mut arc_tags = Arc::new(initial.as_ref().clone());
        let before_ptr = Arc::as_ptr(&arc_tags);
        replace_tags(&mut arc_tags, &globals, &locals);

        // Should be unchanged and reuse the same Arc allocation
        assert_eq!(Arc::as_ptr(&arc_tags), before_ptr);
        assert_eq!(arc_tags.as_slice(), initial.as_slice());
    }

    #[test]
    fn replace_tags_updates_when_only_service_changes() {
        let globals = globals_g1();

        let env = "prod";
        let version = "1.2.3";
        let tags = vec![make_tag("alpha", "beta")];

        let locals_old = build_locals("svc-a", env, version, tags.clone());
        let locals_new = build_locals("svc-b", env, version, tags);

        let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));

        replace_tags(&mut arc_tags, &globals, &locals_new);

        let expected_new = expected_tags(&globals, &locals_new);
        assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
    }

    #[test]
    fn replace_tags_updates_when_only_env_changes() {
        let globals = globals_g1();

        let service = "svc";
        let version = "1.2.3";
        let tags = vec![make_tag("alpha", "beta")];

        let locals_old = build_locals(service, "prod-a", version, tags.clone());
        let locals_new = build_locals(service, "prod-b", version, tags);

        let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));

        replace_tags(&mut arc_tags, &globals, &locals_new);

        let expected_new = expected_tags(&globals, &locals_new);
        assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
    }

    #[test]
    fn replace_tags_updates_when_only_version_changes() {
        let globals = globals_g1();

        let service = "svc";
        let env = "prod";
        let tags = vec![make_tag("alpha", "beta")];

        let locals_old = build_locals(service, env, "1.2.3", tags.clone());
        let locals_new = build_locals(service, env, "2.0.0", tags);

        let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));

        replace_tags(&mut arc_tags, &globals, &locals_new);

        let expected_new = expected_tags(&globals, &locals_new);
        assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
    }

    #[test]
    fn replace_tags_updates_when_dd_tags_change_same_len() {
        let globals = globals_g1();

        let service = "svc";
        let env = "prod";
        let version = "1.2.3";

        // Old locals with two DD_TAGS
        let locals_old = build_locals(
            service,
            env,
            version,
            vec![make_tag("alpha", "beta"), make_tag("foo", "bar")],
        );

        // New locals with same number of DD_TAGS but different values
        let locals_new = build_locals(
            service,
            env,
            version,
            vec![make_tag("alpha", "BETA"), make_tag("foo", "BAR")],
        );

        let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));

        replace_tags(&mut arc_tags, &globals, &locals_new);

        let expected_new = expected_tags(&globals, &locals_new);
        assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
    }

    #[test]
    fn replace_tags_updates_when_dd_tags_change_diff_len() {
        let globals = vec![make_tag("global", "g1")];

        // Old locals
        let mut locals_old = RequestLocals::default();
        locals_old.service = Some("svc".into());
        locals_old.env = Some("prod".into());
        locals_old.version = Some("1.2.3".into());
        locals_old.tags = vec![make_tag("alpha", "beta")];

        // New locals with changed DD_TAGS and different length
        let mut locals_new = RequestLocals::default();
        locals_new.service = Some("svc".into());
        locals_new.env = Some("prod".into());
        locals_new.version = Some("1.2.3".into());
        locals_new.tags = vec![make_tag("gamma", "delta"), make_tag("foo", "bar")];

        let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));

        replace_tags(&mut arc_tags, &globals, &locals_new);

        let expected_new = expected_tags(&globals, &locals_new);
        assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
    }

    #[test]
    fn replace_tags_updates_when_unified_len_increases() {
        let globals = globals_g1();

        let service = "svc";
        let tags = vec![make_tag("alpha", "beta")];

        // Old locals have only service (unified len = 1)
        let locals_old = build_locals(service, "", "", tags.clone());
        // But build_locals always sets Options to Some; emulate None by editing directly
        let mut locals_old = locals_old;
        locals_old.env = None;
        locals_old.version = None;

        // New locals add env (unified len = 2)
        let mut locals_new = build_locals(service, "prod", "", tags);
        locals_new.version = None;

        let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));

        replace_tags(&mut arc_tags, &globals, &locals_new);

        let expected_new = expected_tags(&globals, &locals_new);
        assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
    }

    #[test]
    fn replace_tags_updates_when_unified_len_decreases() {
        let globals = globals_g1();

        let service = "svc";
        let tags = vec![make_tag("alpha", "beta")];

        // Old locals have service + env + version (unified len = 3)
        let locals_old = build_locals(service, "prod", "1.2.3", tags.clone());

        // New locals drop env (unified len = 2)
        let mut locals_new = build_locals(service, "", "1.2.3", tags);
        locals_new.env = None;

        let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));

        replace_tags(&mut arc_tags, &globals, &locals_new);

        let expected_new = expected_tags(&globals, &locals_new);
        assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
    }

    // Property-based tests
    fn make_kv_pairs(len: usize) -> Vec<(String, String)> {
        (0..len)
            .map(|i| (format!("k{i}"), format!("v{i}")))
            .collect()
    }

    fn tags_from_pairs(pairs: &[(String, String)]) -> Vec<Tag> {
        pairs.iter().map(|(k, v)| make_tag(k, v)).collect()
    }

    fn build_locals_opt(
        service: Option<&str>,
        env: Option<&str>,
        version: Option<&str>,
        tags: Vec<(String, String)>,
    ) -> RequestLocals {
        let mut locals = RequestLocals::default();
        locals.service = service.map(|s| s.to_string());
        locals.env = env.map(|s| s.to_string());
        locals.version = version.map(|s| s.to_string());
        locals.tags = tags_from_pairs(&tags);
        locals
    }

    fn clone_locals_for_test(locals: &RequestLocals) -> RequestLocals {
        let mut copy = RequestLocals::default();
        copy.env = locals.env.clone();
        copy.service = locals.service.clone();
        copy.version = locals.version.clone();
        copy.tags = locals.tags.clone();
        copy
    }

    proptest! {
        #[test]
        fn prop_rebuild_when_item_count_diff(
            // unified presence for old and new
            s_old in proptest::bool::ANY,
            e_old in proptest::bool::ANY,
            v_old in proptest::bool::ANY,
            s_new in proptest::bool::ANY,
            e_new in proptest::bool::ANY,
            v_new in proptest::bool::ANY,
            // dd_tags lengths
            len_old in 0usize..4,
            len_new in 0usize..4,
        ) {
            let globals = globals_g1();

            let svc = "svc"; let env = "prod"; let ver = "1.2.3";
            let tags_old = make_kv_pairs(len_old);
            let tags_new = make_kv_pairs(len_new);

            // Build locals based on presence
            let locals_old = build_locals_opt(
                if s_old { Some(svc) } else { None },
                if e_old { Some(env) } else { None },
                if v_old { Some(ver) } else { None },
                tags_old,
            );
            let locals_new = build_locals_opt(
                if s_new { Some(svc) } else { None },
                if e_new { Some(env) } else { None },
                if v_new { Some(ver) } else { None },
                tags_new,
            );

            let unified_len_old = s_old as usize + e_old as usize + v_old as usize;
            let unified_len_new = s_new as usize + e_new as usize + v_new as usize;

            // Ensure the condition (total item count differs). If not, force it by toggling env.
            let (locals_new, _unified_len_new) = if unified_len_old + len_old == unified_len_new + len_new {
                let mut ln = locals_new;
                if e_new { ln.env = None; } else { ln.env = Some(env.to_string()); }
                let new_unified_len = s_new as usize + (!e_new) as usize + v_new as usize;
                (ln, new_unified_len)
            } else {
                (locals_new, unified_len_new)
            };

            let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));
            replace_tags(&mut arc_tags, &globals, &locals_new);
            let expected_new = expected_tags(&globals, &locals_new);
            prop_assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
        }

        #[test]
        fn prop_rebuild_when_same_count_but_content_diff(
            s in proptest::bool::ANY,
            e in proptest::bool::ANY,
            v in proptest::bool::ANY,
            len in 0usize..4,
            change_unified in proptest::bool::ANY,
        ) {
            let globals = globals_g1();

            let svc = "svc"; let env = "prod"; let ver = "1.2.3";
            let tags_pairs = make_kv_pairs(len);
            let locals_old = build_locals_opt(
                if s { Some(svc) } else { None },
                if e { Some(env) } else { None },
                if v { Some(ver) } else { None },
                tags_pairs.clone(),
            );

            // Keep counts the same, but change either unified value or one tag value
            let mut locals_new = clone_locals_for_test(&locals_old);
            if change_unified {
                if s { locals_new.service = Some("svc_changed".into()); }
                else if e { locals_new.env = Some("prod_changed".into()); }
                else if v { locals_new.version = Some("1.2.4".into()); }
                else {
                    // If none present, flip a tag value to ensure diff
                    if let Some(first) = locals_new.tags.get_mut(0) {
                        *first = make_tag("k0", "v0_changed");
                    }
                }
            } else {
                if let Some(first) = locals_new.tags.get_mut(0) {
                    *first = make_tag("k0", "v0_changed");
                } else if s { locals_new.service = Some("svc_changed".into()); }
                else if e { locals_new.env = Some("prod_changed".into()); }
                else if v { locals_new.version = Some("1.2.4".into()); }
            }

            // Sanity: counts same
            let old_len = locals_old.tags.len() + (s as usize + e as usize + v as usize);
            let new_len = locals_new.tags.len()
                + (locals_new.service.is_some() as usize
                + locals_new.env.is_some() as usize
                + locals_new.version.is_some() as usize);
            prop_assume!(old_len == new_len);

            let mut arc_tags = Arc::new(expected_tags(&globals, &locals_old));
            replace_tags(&mut arc_tags, &globals, &locals_new);
            let expected_new = expected_tags(&globals, &locals_new);
            prop_assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
        }

        #[test]
        fn prop_reuse_when_same_count_and_content_equal(
            s in proptest::bool::ANY,
            e in proptest::bool::ANY,
            v in proptest::bool::ANY,
            len in 0usize..4,
        ) {
            let globals = globals_g1();
            let svc = "svc"; let env = "prod"; let ver = "1.2.3";
            let tags_pairs = make_kv_pairs(len);
            let locals = build_locals_opt(
                if s { Some(svc) } else { None },
                if e { Some(env) } else { None },
                if v { Some(ver) } else { None },
                tags_pairs,
            );

            let mut arc_tags = Arc::new(expected_tags(&globals, &locals));
            let before = arc_tags.clone();
            replace_tags(&mut arc_tags, &globals, &locals);
            prop_assert!(Arc::ptr_eq(&arc_tags, &before));
        }

        #[test]
        fn prop_replace_tags_mutations(
            s in proptest::bool::ANY,
            e in proptest::bool::ANY,
            v in proptest::bool::ANY,
            base_len in 0usize..6,
            kind in 0u8..6,
            idx in 0usize..6,
        ) {
            let globals = globals_g1();

            // Build a base locals with total items >= 2
            let unified_count = (s as usize) + (e as usize) + (v as usize);
            let min_needed = if unified_count >= 2 { 0 } else { 2 - unified_count };
            let len = core::cmp::max(base_len, min_needed);

            let svc = "svc"; let env = "prod"; let ver = "1.2.3";
            let tags_pairs = make_kv_pairs(len);
            let base = build_locals_opt(
                if s { Some(svc) } else { None },
                if e { Some(env) } else { None },
                if v { Some(ver) } else { None },
                tags_pairs.clone(),
            );

            let mut mutated = clone_locals_for_test(&base);

            match kind {
                // Toggle unified presence (length change in unified tags)
                0 => {
                    match idx % 3 {
                        0 => { mutated.service = mutated.service.as_ref().map(|_| None).unwrap_or_else(|| Some("svcX".into())); }
                        1 => { mutated.env = mutated.env.as_ref().map(|_| None).unwrap_or_else(|| Some("prodX".into())); }
                        _ => { mutated.version = mutated.version.as_ref().map(|_| None).unwrap_or_else(|| Some("2.0.0".into())); }
                    }
                }
                // Change unified value (same length, different content)
                1 => {
                    match idx % 3 {
                        0 => if mutated.service.is_some() { mutated.service = Some("svc_changed".into()); },
                        1 => if mutated.env.is_some() { mutated.env = Some("prod_changed".into()); },
                        _ => if mutated.version.is_some() { mutated.version = Some("1.2.4".into()); },
                    }
                }
                // Add a tag (length change in DD_TAGS)
                2 => {
                    let idx_new = mutated.tags.len();
                    let key = format!("k{}_new", idx_new);
                    let val = format!("v{}_new", idx_new);
                    mutated.tags.push(make_tag(&key, &val));
                }
                // Remove a tag (length change in DD_TAGS)
                3 => {
                    if !mutated.tags.is_empty() {
                        let i = core::cmp::min(idx, mutated.tags.len() - 1);
                        mutated.tags.remove(i);
                    }
                }
                // Change a tag's key (content change, same length)
                4 => {
                    if !mutated.tags.is_empty() {
                        let i = core::cmp::min(idx, mutated.tags.len() - 1);
                        let val = format!("v{}", i);
                        let key = format!("k{}_changed", i);
                        mutated.tags[i] = make_tag(&key, &val);
                    }
                }
                // Change a tag's value (content change, same length)
                _ => {
                        if !mutated.tags.is_empty() {
                        let i = core::cmp::min(idx, mutated.tags.len() - 1);
                        let key = format!("k{}", i);
                        let val = format!("v{}_changed", i);
                        mutated.tags[i] = make_tag(&key, &val);
                    }
                }
            }

            let expected_old = expected_tags(&globals, &base);
            let expected_new = expected_tags(&globals, &mutated);

            let mut arc_tags = Arc::new(expected_old.clone());
            let before_ptr = Arc::as_ptr(&arc_tags);
            replace_tags(&mut arc_tags, &globals, &mutated);

            if expected_old == expected_new {
                // No change: must reuse the same Arc without modification
                prop_assert!(Arc::as_ptr(&arc_tags) == before_ptr);
                prop_assert_eq!(arc_tags.as_slice(), expected_old.as_slice());
            } else {
                // Changed: content must match new expected; pointer may or may not change
                prop_assert_eq!(arc_tags.as_slice(), expected_new.as_slice());
            }
        }
    }
}
