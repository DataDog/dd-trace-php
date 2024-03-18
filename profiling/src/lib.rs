pub mod bindings;
pub mod capi;
mod clocks;
mod config;
mod logging;
mod pcntl;
pub mod profiling;
mod sapi;
mod string_table;

#[cfg(feature = "allocation_profiling")]
mod allocation;

#[cfg(feature = "exception_profiling")]
mod exception;

#[cfg(feature = "timeline")]
mod timeline;

mod wall_time;

use crate::config::{SystemSettings, INITIAL_SYSTEM_SETTINGS};
use bindings as zend;
use bindings::{ddog_php_prof_php_version_id, ZendExtension, ZendResult};
use clocks::*;
use core::ptr;
use datadog_profiling::exporter::Tag;
use ddcommon::cstr;
use lazy_static::lazy_static;
use libc::c_char;
use log::{debug, error, info, trace, warn};
use once_cell::sync::{Lazy, OnceCell};
use profiling::{LocalRootSpanResourceMessage, Profiler, VmInterrupt};
use sapi::Sapi;
use std::borrow::Cow;
use std::cell::RefCell;
use std::ffi::CStr;
use std::ops::Deref;
use std::os::raw::c_int;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Mutex, Once};
use std::time::{Duration, Instant};
use uuid::Uuid;

use crate::zend::datadog_sapi_globals_request_info;

/// The global profiler. Profiler gets made during the first rinit after an
/// minit, and is destroyed on mshutdown.
static PROFILER: Mutex<Option<Profiler>> = Mutex::new(None);

/// Name of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_NAME: &[u8] = b"datadog-profiling\0";

/// Name of the profiling module and zend_extension, but as a &CStr.
// Safety: null terminated, contains no interior null bytes.
static PROFILER_NAME_CSTR: &CStr = unsafe { CStr::from_bytes_with_nul_unchecked(PROFILER_NAME) };

/// Version of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_VERSION: &[u8] = concat!(env!("CARGO_PKG_VERSION"), "\0").as_bytes();

/// Version ID of PHP at run-time, not the version it was built against at
/// compile-time. Its value is overwritten during minit.
static mut PHP_VERSION_ID: u32 = zend::PHP_VERSION_ID;

lazy_static! {
    static ref LAZY_STATICS_TAGS: Vec<Tag> = {
        vec![
            Tag::from_value("language:php").expect("language tag to be valid"),
            // Safety: calling getpid() is safe.
            Tag::new("process_id", unsafe { libc::getpid() }.to_string())
                .expect("process_id tag to be valid"),
            Tag::from_value(concat!("profiler_version:", env!("CARGO_PKG_VERSION")))
                .expect("profiler_version tag to be valid"),
            Tag::new("runtime-id", &runtime_id().to_string()).expect("runtime-id tag to be valid"),
        ]
    };

    /// The version of PHP at runtime, not the version compiled against. Sent
    /// as a profile tag.
    static ref PHP_VERSION: String = {
        // Reflection uses the PHP_VERSION as its version, see:
        // https://github.com/php/php-src/blob/PHP-8.1.4/ext/reflection/php_reflection.h#L25
        // https://github.com/php/php-src/blob/PHP-8.1.4/ext/reflection/php_reflection.c#L7157
        // It goes back to at least PHP 7.1:
        // https://github.com/php/php-src/blob/PHP-7.1/ext/reflection/php_reflection.h

        // Safety: CStr string is null-terminated without any interior null bytes.
        let module_name = unsafe { CStr::from_bytes_with_nul_unchecked(b"Reflection\0") };
        get_module_version(module_name)
            .expect("Reflection's zend_module_entry to be found and contain a valid string")
    };

    /// The Server API the profiler is running under.
    static ref SAPI: Sapi = {
        // Safety: sapi_module is initialized before minit and there should be
        // no concurrent threads.
        let sapi_module = unsafe { zend::sapi_module };
        if sapi_module.name.is_null() {
            panic!("the sapi_module's name is a null pointer");
        }

        // Safety: value has been checked for NULL; I haven't checked that the
        // engine ensures its length is less than `isize::MAX`, but it is a
        // risk I'm willing to take.
        let sapi_name = unsafe { CStr::from_ptr(sapi_module.name) };
        Sapi::from_name(sapi_name.to_string_lossy().as_ref())
    };

    // Safety: PROFILER_NAME is a byte slice that satisfies the safety requirements.
    // Panic: we own this string and it should be UTF8 (see PROFILER_NAME above).
    static ref PROFILER_NAME_STR: &'static str = PROFILER_NAME_CSTR.to_str().unwrap();

    // Safety: PROFILER_VERSION is a byte slice that satisfies the safety requirements.
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

/// The function `get_module` is what makes this a PHP module. Please do not
/// call this directly; only let it be called by the engine. Generally it is
/// only called once, but if someone accidentally loads the module twice then
/// it might get called more than once, though it will warn and not use the
/// consecutive return value.
#[no_mangle]
pub extern "C" fn get_module() -> &'static mut zend::ModuleEntry {
    static DEPS: [zend::ModuleDep; 4] = [
        zend::ModuleDep::required(cstr!("standard")),
        zend::ModuleDep::required(cstr!("json")),
        zend::ModuleDep::optional(cstr!("ddtrace")),
        zend::ModuleDep::end(),
    ];

    /* In PHP modules written in C, this just returns the address of a global,
     * mutable variable. In Rust, you cannot initialize such a complicated
     * global variable because of initialization order issues that have been
     * found through decades of C++ experience.
     * There are a variety of ways to deal with this; this is just one way.
     */
    static mut MODULE: Lazy<zend::ModuleEntry> = Lazy::new(|| zend::ModuleEntry {
        name: PROFILER_NAME.as_ptr(),
        // Safety: php_ffi.c defines this correctly
        functions: unsafe { bindings::ddog_php_prof_functions },
        module_startup_func: Some(minit),
        module_shutdown_func: Some(mshutdown),
        request_startup_func: Some(rinit),
        request_shutdown_func: Some(rshutdown),
        info_func: Some(minfo),
        version: PROFILER_VERSION.as_ptr(),
        post_deactivate_func: Some(prshutdown),
        deps: DEPS.as_ptr(),
        ..Default::default()
    });

    // SAFETY: well, it's as least as safe as what every single C extension does.
    unsafe { &mut MODULE }
}

/* Important note on the PHP lifecycle:
 * Based on how some SAPIs work and the documentation, one might expect that
 * MINIT is called once per process, but this is only sort-of true. Some SAPIs
 * will call MINIT once and then fork for additional processes.
 * This means you cannot do certain things in MINIT and have them work across
 * all SAPIs, like spawn threads.
 *
 * Additionally, when Apache does a reload it will go through the shutdown
 * routines and then in the same process do the startup routines, so MINIT can
 * actually be called more than once per process as well. This means some
 * mechanisms like std::sync::Once::call_once may not be suitable.
 * Be careful out there!
 */
extern "C" fn minit(_type: c_int, module_number: c_int) -> ZendResult {
    /* When developing the extension, it's useful to see log messages that
     * occur before the user can configure the log level. However, if we
     * initialized the logger here unconditionally then they'd have no way to
     * hide these messages. That's why it's done only for debug builds.
     */
    #[cfg(debug_assertions)]
    {
        logging::log_init(log::LevelFilter::Trace);
        trace!("MINIT({_type}, {module_number})");
    }

    #[cfg(target_vendor = "apple")]
    {
        /* If PHP forks and certain ObjC classes are not initialized before the
         * fork, then on High Sierra and above the child process will crash,
         * for example:
         * > objc[25938]: +[__NSCFConstantString initialize] may have been in
         * > progress in another thread when fork() was called. We cannot
         * > safely call it or ignore it in the fork() child process. Crashing
         * > instead. Set a breakpoint on objc_initializeAfterForkError to
         * > debug.
         * In our case, it's things related to TLS that fail, so when we
         * support forking, load this at the beginning:
         * let _ = ddcommon::connector::load_root_certs();
         */
    }

    // SAFETY: setting global mutable value in MINIT.
    unsafe { PHP_VERSION_ID = ddog_php_prof_php_version_id() };

    config::minit(module_number);

    /* Use a hybrid extension hack to load as a module but have the
     * zend_extension hooks available:
     * https://www.phpinternalsbook.com/php7/extensions_design/zend_extensions.html#hybrid-extensions
     * In this case, use the same technique as the tracer: transfer the module
     * handle to the zend_extension as extensions have longer lifetimes than
     * modules in the engine.
     */
    let handle = {
        /* The engine copies the module entry we provide it, so we have to
         * lookup the module entry in the registry and modify it there
         * instead of just modifying the result of get_module().
         *
         * I modified the engine for PHP 8.2 to stop copying the module:
         * https://github.com/php/php-src/pull/8551
         * At the time of this writing, PHP 8.2 isn't out yet so it's possible
         * it may get reverted if issues are found.
         */
        let str = PROFILER_NAME_CSTR.as_ptr();
        let len = PROFILER_NAME.len() - 1; // ignore trailing null byte

        // Safety: str is valid for at least len values.
        let ptr = unsafe { zend::datadog_get_module_entry(str, len) };
        if ptr.is_null() {
            error!("Unable to locate our own module in the engine registry.");
            return ZendResult::Failure;
        }

        /* Safety: `ptr` was checked for nullability already. Transferring the
         * handle from the module to the extension extends the lifetime, not
         * shortens it, so it's safe. But of course, be sure the code below
         * actually passes it to the extension.
         */
        unsafe {
            let module = &mut *ptr;
            let handle = module.handle;
            module.handle = ptr::null_mut();
            handle
        }
    };

    /* Currently, the engine is always copying this struct. Every time a new
     * PHP version is released, we should double check zend_register_extension
     * to ensure the address is not mutated nor stored. Well, hopefully we
     * catch it _before_ a release.
     */
    let extension = ZendExtension {
        name: PROFILER_NAME.as_ptr(),
        version: PROFILER_VERSION.as_ptr(),
        author: b"Datadog\0".as_ptr(),
        url: b"https://github.com/DataDog\0".as_ptr(),
        copyright: b"Copyright Datadog\0".as_ptr(),
        startup: Some(startup),
        shutdown: Some(shutdown),
        activate: Some(activate),
        ..Default::default()
    };

    // Safety: during minit there shouldn't be any threads to race against these writes.
    unsafe { wall_time::minit() };

    /* Safety: all arguments are valid for this C call.
     * Note that on PHP 7 this never fails, and on PHP 8 it returns void.
     */
    unsafe { zend::zend_register_extension(&extension, handle) };

    #[cfg(feature = "allocation_profiling")]
    allocation::allocation_profiling_minit();

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

    /* ZAI config may be accessed indirectly via other modules RSHUTDOWN, so
     * delay this until the last possible time.
     */
    unsafe { bindings::zai_config_rshutdown() };

    #[cfg(feature = "timeline")]
    timeline::timeline_prshutdown();

    ZendResult::Success
}

pub struct RequestLocals {
    pub env: Option<Cow<'static, str>>,
    pub service: Option<Cow<'static, str>>,
    pub version: Option<Cow<'static, str>>,

    /// SystemSettings are global. Note that if this is being read in fringe
    /// conditions such as in mshutdown when there were no requests served,
    /// then the settings are still memory safe but they may not have the real
    /// configuration. Instead they have a best-effort values such as
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
            system_settings: ptr::NonNull::from(INITIAL_SYSTEM_SETTINGS.deref()),
            interrupt_count: AtomicU32::new(0),
            vm_interrupt_addr: ptr::null_mut(),
        }
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
    /// the on the values sent in the SAPI for env, service, version, etc.
    /// They get reset at the end of the request.
    static TAGS: RefCell<Arc<Vec<Tag>>> = RefCell::new(Arc::new(Vec::new()));
}

/// Gets the runtime-id for the process. Do not call before RINIT!
fn runtime_id() -> &'static Uuid {
    RUNTIME_ID
        .get_or_init(|| unsafe { ddtrace_runtime_id.as_ref() }.map_or_else(Uuid::new_v4, |u| *u))
}

extern "C" fn activate() {
    // Safety: calling in activate as required.
    unsafe { profiling::activate_run_time_cache() };
}

/// The mut here is *only* for resetting this back to uninitialized each minit.
static mut ZAI_CONFIG_ONCE: Once = Once::new();
/// The mut here is *only* for resetting this back to uninitialized each minit.
static mut RINIT_ONCE: Once = Once::new();

/* If Failure is returned the VM will do a C exit; try hard to avoid that,
 * using it for catastrophic errors only.
 */
extern "C" fn rinit(_type: c_int, _module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("RINIT({_type}, {_module_number})");

    // SAFETY: not being mutated during rinit.
    unsafe { &ZAI_CONFIG_ONCE }.call_once(|| unsafe {
        bindings::zai_config_first_time_rinit(true);
        config::first_rinit();
    });

    unsafe { bindings::zai_config_rinit() };

    // Safety: We are after first rinit and before config mshutdown.
    let system_settings = unsafe { SystemSettings::get() };

    // initialize the thread local storage and cache some items
    REQUEST_LOCALS.with(|cell| {
        let mut locals = cell.borrow_mut();
        // Safety: we are in rinit on a PHP thread.
        locals.vm_interrupt_addr = unsafe { zend::datadog_php_profiling_vm_interrupt_addr() };
        locals.interrupt_count.store(0, Ordering::SeqCst);

        // Safety: We are after first rinit and before mshutdown.
        unsafe {
            locals.env = config::env();
            locals.service = config::service().or_else(|| {
                match *SAPI {
                    Sapi::Cli => {
                        // Safety: sapi globals are safe to access during rinit
                        SAPI.request_script_name(datadog_sapi_globals_request_info())
                            .or(Some(Cow::Borrowed("cli.command")))
                    }
                    _ => Some(Cow::Borrowed("web.request")),
                }
            });
            locals.version = config::version();
        }
        locals.system_settings = system_settings;
    });

    // SAFETY: still safe to access in rinit after first_rinit.
    let system_settings = unsafe { system_settings.as_ref() };

    unsafe { &RINIT_ONCE }.call_once(|| {
        if system_settings.profiling_enabled {
            /* Safety: sapi_module is initialized by rinit and shouldn't be
             * modified at this point (safe to read values).
             */
            let sapi_module = unsafe { &zend::sapi_module };
            if sapi_module.pretty_name.is_null() {
                // Safety: I'm willing to bet the module name is less than `isize::MAX`.
                let name = unsafe { CStr::from_ptr(sapi_module.name) }.to_string_lossy();
                warn!("The SAPI module {name}'s pretty name was not set!")
            } else {
                // Safety: I'm willing to bet the module pretty name is less than `isize::MAX`.
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
    });

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

    // reminder: this cannot be done in minit because of Apache forking model
    {
        /* It would be nice if this could be cheaper. OnceCell would be cheaper
         * but it doesn't quite fit the model, as going back to uninitialized
         * requires either a &mut or .take(), and neither works for us (unless
         * we go for unsafe, which is what we are trying to avoid).
         */
        let mut profiler = PROFILER.lock().unwrap();
        if profiler.is_none() {
            *profiler = Some(Profiler::new(system_settings))
        }
    };

    if system_settings.profiling_enabled {
        REQUEST_LOCALS.with(|cell| {
            let locals = cell.borrow();
            let cpu_time_enabled = system_settings.profiling_experimental_cpu_time_enabled;
            CLOCKS.with(|cell| cell.borrow_mut().initialize(cpu_time_enabled));

            TAGS.with(|cell| {
                let mut tags = LAZY_STATICS_TAGS.clone();
                add_optional_tag(&mut tags, "service", &locals.service);
                add_optional_tag(&mut tags, "env", &locals.env);
                add_optional_tag(&mut tags, "version", &locals.version);
                // This should probably be "language_version", but this is the
                // standardized tag name.
                add_tag(&mut tags, "runtime_version", PHP_VERSION.as_str());
                add_tag(&mut tags, "php.sapi", SAPI.as_ref());
                // In case we ever add PHP debug build support, we should add `zend-zts-debug` and
                // `zend-nts-debug`. For the time being we only support `zend-zts-ndebug` and
                // `zend-nts-ndebug`
                let runtime_engine = if cfg!(php_zts) {
                    "zend-zts-ndebug"
                } else {
                    "zend-nts-ndebug"
                };
                add_tag(&mut tags, "runtime_engine", runtime_engine);
                cell.replace(Arc::new(tags));
            });

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count as *const AtomicU32,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                profiler.add_interrupt(interrupt);
            }
        });
    } else {
        TAGS.with(|cell| cell.replace(Arc::default()));
    }

    #[cfg(feature = "allocation_profiling")]
    allocation::allocation_profiling_rinit();

    // SAFETY: called after config is initialized.
    #[cfg(feature = "timeline")]
    unsafe {
        timeline::timeline_rinit()
    };

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
        Ok(tag) => {
            tags.push(tag);
        }
        Err(err) => {
            warn!("invalid tag: {err}");
        }
    }
}

extern "C" fn rshutdown(_type: c_int, _module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("RSHUTDOWN({_type}, {_module_number})");

    #[cfg(php_run_time_cache)]
    {
        profiling::FUNCTION_CACHE_STATS.with(|cell| {
            let stats = cell.borrow();
            let hit_rate = stats.hit_rate();
            debug!("Process cumulative {stats:?} hit_rate: {hit_rate}");
        });
    }

    REQUEST_LOCALS.with(|cell| {
        let locals = cell.borrow();
        let system_settings = locals.system_settings();

        if system_settings.profiling_enabled {
            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                profiler.remove_interrupt(interrupt);
            }
        }
    });

    #[cfg(feature = "allocation_profiling")]
    allocation::allocation_profiling_rshutdown();

    ZendResult::Success
}

/// Prints the module info. Calls many C functions from the Zend Engine,
/// including calling variadic functions. It's essentially all unsafe, so be
/// careful, and do not call this manually (only let the engine call it).
unsafe extern "C" fn minfo(module_ptr: *mut zend::ModuleEntry) {
    #[cfg(debug_assertions)]
    trace!("MINFO({:p})", module_ptr);

    let module = &*module_ptr;

    REQUEST_LOCALS.with(|cell| {
        let locals = cell.borrow();
        let system_settings = locals.system_settings();
        let yes: &[u8] = b"true\0";
        let yes_exp: &[u8] = b"true (all experimental features enabled)\0";
        let no: &[u8] = b"false\0";
        let no_all: &[u8] = b"false (profiling disabled)\0";
        zend::php_info_print_table_start();
        zend::php_info_print_table_row(2, b"Version\0".as_ptr(), module.version);
        zend::php_info_print_table_row(
            2,
            b"Profiling Enabled\0".as_ptr(),
            if system_settings.profiling_enabled { yes } else { no },
        );

        zend::php_info_print_table_row(
            2,
            b"Profiling Experimental Features Enabled\0".as_ptr(),
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
            b"Experimental CPU Time Profiling Enabled\0".as_ptr(),
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
                    b"Allocation Profiling Enabled\0".as_ptr(),
                    if system_settings.profiling_allocation_enabled {
                        yes
                    } else if zend::ddog_php_jit_enabled() {
                        b"Not available due to JIT being active, see https://github.com/DataDog/dd-trace-php/pull/2088 for more information.\0"
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
                    b"Timeline Enabled\0".as_ptr(),
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
                    b"Timeline Enabled\0".as_ptr(),
                    b"Not available. The profiler was build without timeline support.\0"
                );
            }
        }

        cfg_if::cfg_if! {
            if #[cfg(feature = "exception_profiling")] {
                zend::php_info_print_table_row(
                    2,
                    b"Exception Profiling Enabled\0".as_ptr(),
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
                    b"Exception Profiling Enabled\0".as_ptr(),
                    b"Not available. The profiler was built without exception profiling support.\0"
                );
            }
        }

        zend::php_info_print_table_row(
            2,
            b"Endpoint Collection Enabled\0".as_ptr(),
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
            b"Platform's CPU Time API Works\0".as_ptr(),
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
            b"Profiling Log Level\0".as_ptr(),
            printable_log_level.as_ptr()
        );

        let key = b"Profiling Agent Endpoint\0".as_ptr();
        let agent_endpoint = format!("{}\0", system_settings.uri);
        zend::php_info_print_table_row(2, key, agent_endpoint.as_ptr());

        let vars = [
            (b"Application's Environment (DD_ENV)\0", &locals.env),
            (b"Application's Service (DD_SERVICE)\0", &locals.service),
            (b"Application's Version (DD_VERSION)\0", &locals.version),
        ];

        for (key, value) in vars {
            let mut value = match value {
                Some(cowstr) => cowstr.clone().into_owned(),
                None => String::new(),
            };
            value.push('\0');
            zend::php_info_print_table_row(2, key, value.as_ptr());
        }

        zend::php_info_print_table_end();

        zend::display_ini_entries(module_ptr);
    });
}

extern "C" fn mshutdown(_type: c_int, _module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("MSHUTDOWN({_type}, {_module_number})");

    // SAFETY: being called before [config::shutdown].
    #[cfg(feature = "timeline")]
    unsafe {
        timeline::timeline_mshutdown()
    };

    #[cfg(feature = "exception_profiling")]
    exception::exception_profiling_mshutdown();

    let mut profiler = PROFILER.lock().unwrap();
    if let Some(profiler) = profiler.as_mut() {
        profiler.stop(Duration::from_secs(1));
    }

    ZendResult::Success
}

fn get_module_version(module_name: &CStr) -> Option<String> {
    // Safety: passing a CStr.as_ptr() will be a valid *const char.
    let module_version = unsafe { zend::zend_get_module_version(module_name.as_ptr()) };
    // It shouldn't be NULL as the engine calls `strlen` on it when it's
    // registered; just being defensive.
    if module_version.is_null() {
        return None;
    }

    // Safety: module_version isn't null (checked above). It's also incredibly
    // unlikely that the version string is longer than i64::MAX, so this isn't
    // worth our time to check.
    let cstr = unsafe { CStr::from_ptr(module_version) };
    let version = cstr.to_string_lossy().to_string();
    Some(version)
}

extern "C" fn startup(extension: *mut ZendExtension) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("startup({:p})", extension);

    // Safety: called during startup hook with correct params.
    unsafe { zend::datadog_php_profiling_startup(extension) };

    #[cfg(php_run_time_cache)]
    // Safety: calling this in startup/minit as required.
    unsafe {
        bindings::ddog_php_prof_function_run_time_cache_init(PROFILER_NAME_CSTR.as_ptr())
    };

    // Safety: calling this in zend_extension startup.
    unsafe {
        pcntl::startup();
        timeline::timeline_startup();
    }

    #[cfg(feature = "allocation_profiling")]
    allocation::allocation_profiling_startup();

    ZendResult::Success
}

extern "C" fn shutdown(_extension: *mut ZendExtension) {
    #[cfg(debug_assertions)]
    trace!("shutdown({:p})", _extension);

    let mut profiler = PROFILER.lock().unwrap();
    if let Some(profiler) = profiler.take() {
        profiler.shutdown(Duration::from_secs(2));
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
    REQUEST_LOCALS.with(|cell| {
        let locals = cell.borrow();
        let system_settings = locals.system_settings();
        if system_settings.profiling_enabled && system_settings.profiling_endpoint_collection_enabled {
            // Only gather Endpoint Profiling data for web spans, partly for PII reasons.
            if span_type != "web" {
                debug!(
                    "Local root span id {local_root_span_id} ended but did not have a span type of 'web' (actual: '{span_type}'), so Endpoint Profiling data will not be sent."
                );
                return;
            }

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
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
}
