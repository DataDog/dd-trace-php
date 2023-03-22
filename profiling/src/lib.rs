mod bindings;
pub mod capi;
mod config;
mod logging;
mod pcntl;
mod profiling;
mod sapi;
mod string_table;

use bindings as zend;
use bindings::{sapi_globals, ZendExtension, ZendResult};
use config::AgentEndpoint;
use datadog_profiling::exporter::{Tag, Uri};
use lazy_static::lazy_static;
use libc::c_char;
use log::{debug, error, info, trace, warn, LevelFilter};
use once_cell::sync::OnceCell;
use profiling::{LocalRootSpanResourceMessage, Profiler, VmInterrupt};
use sapi::Sapi;
use std::borrow::Cow;
use std::cell::RefCell;
use std::ffi::CStr;
use std::mem::MaybeUninit;
use std::ops::DerefMut;
use std::os::raw::c_int;
use std::path::PathBuf;
use std::str::FromStr;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Mutex, Once};
use std::time::{Duration, Instant};
use uuid::Uuid;

#[cfg(feature = "allocation_profiling")]
use rand_distr::{Distribution, Poisson};

#[cfg(feature = "allocation_profiling")]
use crate::bindings::{
    datadog_php_install_handler, datadog_php_zif_handler, ddog_php_prof_copy_long_into_zval,
};

/// The version of PHP at runtime, not the version compiled against. Sent as
/// a profile tag.
static PHP_VERSION: OnceCell<String> = OnceCell::new();

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

lazy_static! {
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

/// The Server API the profiler is running under.
static SAPI: OnceCell<Sapi> = OnceCell::new();

/// The version of the ZendEngine at runtime, not the version compiled against.
/// It's currently unused, but I hope to send it as a metric or something soon
/// so I'm keeping it here so I don't have to look up how to get it again.
static ZEND_VERSION: OnceCell<String> = OnceCell::new();

/// The function `get_module` is what makes this a PHP module. Please do not
/// call this directly; only let it be called by the engine. Generally it is
/// only called once, but if someone accidentally loads the module twice then
/// it might get called more than once, though it will warn and not use the
/// consecutive return value.
#[no_mangle]
pub extern "C" fn get_module() -> &'static mut zend::ModuleEntry {
    /* In PHP modules written in C, this just returns the address of a global,
     * mutable variable. In Rust, you cannot initialize such a complicated
     * global variable because of initialization order issues that have been
     * found through decades of C++ experience.
     * There are a variety of ways to deal with this. Since this function is
     * only _supposed_ to be called once, I've taken the stance to just leak
     * the result which avoids unsafe code and unnecessary locks.
     */

    static DEPS: [zend::ModuleDep; 2] = [
        // Safety: string is nul terminated with no interior nul bytes.
        zend::ModuleDep::optional(unsafe { CStr::from_bytes_with_nul_unchecked(b"ddtrace\0") }),
        zend::ModuleDep::end(),
    ];

    let module = zend::ModuleEntry {
        name: PROFILER_NAME.as_ptr(),
        module_startup_func: Some(minit),
        module_shutdown_func: Some(mshutdown),
        request_startup_func: Some(rinit),
        request_shutdown_func: Some(rshutdown),
        info_func: Some(minfo),
        version: PROFILER_VERSION.as_ptr(),
        post_deactivate_func: Some(prshutdown),
        deps: DEPS.as_ptr(),
        ..Default::default()
    };

    Box::leak(Box::new(module))
}

/// The engine's previous `zend_interrupt_function` value, if there is one.
/// Note that because of things like Apache reload which call minit more than
/// once per process, this cannot be made into a OnceCell nor lazy_static.
static mut PREV_INTERRUPT_FUNCTION: MaybeUninit<Option<zend::VmInterruptFn>> =
    MaybeUninit::uninit();

/// The engine's previous `zend::zend_execute_internal` value, or
/// `zend::execute_internal` if none. This is a highly active path, so although
/// it could be made safe with Mutex, the cost is too high.
static mut PREV_EXECUTE_INTERNAL: MaybeUninit<
    unsafe extern "C" fn(execute_data: *mut zend::zend_execute_data, return_value: *mut zend::zval),
> = MaybeUninit::uninit();

#[cfg(feature = "allocation_profiling")]
static mut GC_MEM_CACHES_HANDLER: zend::InternalFunctionHandler = None;

/// The engine's previous custom allocation function, if there is one.
#[cfg(feature = "allocation_profiling")]
static mut PREV_CUSTOM_MM_ALLOC: Option<zend::VmMmCustomAllocFn> = None;

/// The engine's previous custom reallocation function, if there is one.
#[cfg(feature = "allocation_profiling")]
static mut PREV_CUSTOM_MM_REALLOC: Option<zend::VmMmCustomReallocFn> = None;

/// The engine's previous custom free function, if there is one.
#[cfg(feature = "allocation_profiling")]
static mut PREV_CUSTOM_MM_FREE: Option<zend::VmMmCustomFreeFn> = None;

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
extern "C" fn minit(r#type: c_int, module_number: c_int) -> ZendResult {
    /* When developing the extension, it's useful to see log messages that
     * occur before the user can configure the log level. However, if we
     * initialized the logger here unconditionally then they'd have no way to
     * hide these messages. That's why it's done only for debug builds.
     */
    #[cfg(debug_assertions)]
    {
        logging::log_init(LevelFilter::Trace);
        trace!("MINIT({}, {})", r#type, module_number);
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

    // Ignore unused result; use SAPI.get() which returns an Option if it's uninitialized.
    let _ = SAPI.get_or_try_init(|| {
        // Safety: sapi_module is initialized by minit; should be no concurrent threads.
        let sapi_module = unsafe { zend::sapi_module };
        if !sapi_module.name.is_null() {
            /* Safety: value has been checked for NULL; I haven't checked that
             * the engine ensures its length is less than `isize::MAX`, but it
             * is a risk I'm willing to take.
             */
            let sapi_name = unsafe { CStr::from_ptr(sapi_module.name) };
            Ok(Sapi::from_name(sapi_name.to_string_lossy().as_ref()))
        } else {
            Err(())
        }
    });

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
            module.handle = std::ptr::null_mut();
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
        ..Default::default()
    };

    // Safety: during minit there shouldn't be any threads to race against these writes.
    unsafe {
        PREV_INTERRUPT_FUNCTION.write(zend::zend_interrupt_function);
        PREV_EXECUTE_INTERNAL.write(zend::zend_execute_internal.unwrap_or(zend::execute_internal));

        zend::zend_interrupt_function = Some(if zend::zend_interrupt_function.is_some() {
            interrupt_function_wrapper
        } else {
            capi::datadog_profiling_interrupt_function
        });

        zend::zend_execute_internal = Some(execute_internal);
    };

    /* Safety: all arguments are valid for this C call.
     * Note that on PHP 7 this never fails, and on PHP 8 it returns void.
     */
    unsafe { zend::zend_register_extension(&extension, handle) };

    ZendResult::Success
}

extern "C" fn prshutdown() -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("PRSHUTDOWN");

    /* ZAI config may be accessed indirectly via other modules RSHUTDOWN, so
     * delay this until the last possible time.
     */
    unsafe { bindings::zai_config_rshutdown() };

    ZendResult::Success
}

pub struct RequestLocals {
    pub env: Option<Cow<'static, str>>,
    pub interrupt_count: AtomicU32,
    pub last_cpu_time: Option<cpu_time::ThreadTime>,
    pub last_wall_time: Instant,
    pub profiling_enabled: bool,
    pub profiling_endpoint_collection_enabled: bool,
    pub profiling_experimental_cpu_time_enabled: bool,
    pub profiling_experimental_allocation_enabled: bool,
    pub profiling_log_level: LevelFilter, // Only used for minfo
    pub service: Option<Cow<'static, str>>,
    pub tags: Arc<Vec<Tag>>,
    pub uri: Box<AgentEndpoint>,
    pub version: Option<Cow<'static, str>>,
    pub vm_interrupt_addr: *const AtomicBool,
}

/// take a sample every X bytes
/// this value is temporary but the overhead looks promising, Go profiler samples every 512 KiB
#[cfg(feature = "allocation_profiling")]
const ALLOCATION_PROFILING_INTERVAL: f64 = 1024.0 * 512.0;

#[cfg(feature = "allocation_profiling")]
pub struct AllocationProfilingStats {
    /// number of bytes until next sample collection
    next_sample: i64,
}

#[cfg(feature = "allocation_profiling")]
impl AllocationProfilingStats {
    fn new() -> AllocationProfilingStats {
        AllocationProfilingStats {
            next_sample: AllocationProfilingStats::next_sampling_interval(),
        }
    }

    fn next_sampling_interval() -> i64 {
        Poisson::new(ALLOCATION_PROFILING_INTERVAL)
            .unwrap()
            .sample(&mut rand::thread_rng()) as i64
    }

    fn track_allocation(&mut self, len: u64) {
        self.next_sample -= len as i64;

        if self.next_sample > 0 {
            return;
        }

        let scale = 1.0 / (1.0 - (len as f64 * -1.0 / ALLOCATION_PROFILING_INTERVAL).exp());

        let count = 1.0 * scale;
        let bytes = len as f64 * scale;

        self.next_sample = AllocationProfilingStats::next_sampling_interval();

        REQUEST_LOCALS.with(|cell| {
            // Panic: there might already be a mutable reference to `REQUEST_LOCALS`
            let locals = cell.try_borrow();
            if locals.is_err() {
                return;
            }
            let locals = locals.unwrap();

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
                unsafe {
                    profiler.collect_allocations(
                        zend::ddog_php_prof_get_current_execute_data(),
                        count as i64,
                        bytes as i64,
                        &locals,
                    )
                };
            }
        });
    }
}

fn static_tags() -> Vec<Tag> {
    vec![
        Tag::from_value("language:php").expect("static tags to be valid"),
        // Safety: calling getpid() is safe.
        Tag::new("process_id", unsafe { libc::getpid() }.to_string())
            .expect("static tags to be valid"),
        Tag::from_value(concat!("profiler_version:", env!("CARGO_PKG_VERSION")))
            .expect("static tags to be valid"),
    ]
}

thread_local! {
    static REQUEST_LOCALS: RefCell<RequestLocals> = RefCell::new(RequestLocals {
        env: None,
        interrupt_count: AtomicU32::new(0),
        last_cpu_time: None,
        last_wall_time: Instant::now(),
        profiling_enabled: false,
        profiling_endpoint_collection_enabled: true,
        profiling_experimental_cpu_time_enabled: true,
        profiling_experimental_allocation_enabled: true,
        profiling_log_level: LevelFilter::Off,
        service: None,
        tags: Arc::new(static_tags()),
        uri: Box::new(AgentEndpoint::default()),
        version: None,
        vm_interrupt_addr: std::ptr::null_mut(),
    });

    #[cfg(feature = "allocation_profiling")]
    static ALLOCATION_PROFILING_STATS: RefCell<AllocationProfilingStats> = RefCell::new(AllocationProfilingStats::new());
}

/// Gets the runtime-id for the process.
fn runtime_id() -> Uuid {
    *RUNTIME_ID.get_or_init(Uuid::new_v4)
}

/* If Failure is returned the VM will do a C exit; try hard to avoid that,
 * using it for catastrophic errors only.
 */
extern "C" fn rinit(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("RINIT({}, {})", r#type, module_number);

    /* At the moment, logging is truly global, so init it exactly once whether
     * profiling is enabled or not.
     */
    static ONCE: Once = Once::new();
    ONCE.call_once(|| {
        unsafe { bindings::zai_config_first_time_rinit() };
    });

    unsafe { bindings::zai_config_rinit() };

    // Safety: We are after first rinit and before mshutdown.
    let (
        profiling_enabled,
        profiling_endpoint_collection_enabled,
        profiling_experimental_cpu_time_enabled,
        profiling_experimental_allocation_enabled,
        log_level,
        output_pprof,
    ) = unsafe {
        (
            config::profiling_enabled(),
            config::profiling_endpoint_collection_enabled(),
            config::profiling_experimental_cpu_time_enabled(),
            config::profiling_experimental_allocation_enabled(),
            config::profiling_log_level(),
            config::profiling_output_pprof(),
        )
    };

    // initialize the thread local storage and cache some items
    REQUEST_LOCALS.with(|cell| {
        let mut locals = cell.borrow_mut();
        // Safety: we are in rinit on a PHP thread.
        locals.vm_interrupt_addr = unsafe { zend::datadog_php_profiling_vm_interrupt_addr() };
        locals.interrupt_count.store(0, Ordering::SeqCst);

        locals.profiling_enabled = profiling_enabled;
        locals.profiling_endpoint_collection_enabled = profiling_endpoint_collection_enabled;
        locals.profiling_experimental_cpu_time_enabled = profiling_experimental_cpu_time_enabled;
        locals.profiling_experimental_allocation_enabled =
            profiling_experimental_allocation_enabled;
        locals.profiling_log_level = log_level;

        // Safety: We are after first rinit and before mshutdown.
        unsafe {
            locals.env = config::env();
            locals.service = config::service().or_else(|| {
                SAPI.get().and_then(|sapi| match sapi {
                    Sapi::Cli => {
                        // Safety: sapi globals are safe to access during rinit
                        sapi.request_script_name(&sapi_globals)
                            .or(Some(Cow::Borrowed("cli.command")))
                    }
                    _ => Some(Cow::Borrowed("web.request")),
                })
            });
            locals.version = config::version();

            // Select agent URI/UDS
            let agent_host = config::agent_host();
            let trace_agent_port = config::trace_agent_port();
            let trace_agent_url = config::trace_agent_url();
            let endpoint = detect_uri_from_config(trace_agent_url, agent_host, trace_agent_port);
            locals.uri = Box::new(endpoint);

            // todo: tags
        }
    });

    static ONCE2: Once = Once::new();
    ONCE2.call_once(|| {
        // Don't log when profiling is disabled as that can mess up tests.
        let profiling_log_level = if profiling_enabled {
            log_level
        } else {
            LevelFilter::Off
        };

        #[cfg(not(debug_assertions))]
        logging::log_init(profiling_log_level);
        #[cfg(debug_assertions)]
        log::set_max_level(profiling_log_level);

        if profiling_enabled {
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
                if SAPI.get().unwrap_or(&Sapi::Unknown) != &Sapi::Unknown {
                    debug!("Recognized SAPI: {pretty_name}.");
                } else {
                    warn!("Unrecognized SAPI: {pretty_name}.");
                }
            }
            if let Err(err) = cpu_time::ThreadTime::try_now() {
                if profiling_experimental_cpu_time_enabled {
                    warn!("CPU Time collection was enabled but collection failed: {err}");
                } else {
                    debug!("CPU Time collection was not enabled and isn't available: {err}");
                }
            } else if profiling_experimental_cpu_time_enabled {
                info!("CPU Time profiling enabled.");
            }
        }
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
            *profiler = Some(Profiler::new(output_pprof))
        }
    };

    if profiling_enabled {
        REQUEST_LOCALS.with(|cell| {
            let mut locals = cell.borrow_mut();

            locals.last_wall_time = Instant::now();
            if locals.profiling_experimental_cpu_time_enabled {
                let now = cpu_time::ThreadTime::try_now()
                    .expect("CPU time to work since it's worked before during this process");
                locals.last_cpu_time = Some(now);
            }

            {
                // Calling make_mut would be more efficient, but we get into
                // issues with borrowing part of `locals` mutably and others
                // immutably. So, we clone the tags and replace locals.tags
                // later.
                let mut tags = (*locals.tags).clone();

                add_optional_tag(&mut tags, "service", &locals.service);
                add_optional_tag(&mut tags, "env", &locals.env);
                add_optional_tag(&mut tags, "version", &locals.version);

                let runtime_id = runtime_id();
                if !runtime_id.is_nil() {
                    add_tag(&mut tags, "runtime-id", &runtime_id.to_string());
                }

                /* This should probably be "language_version", but this is
                 * the tag that was standardized for this purpose. */
                add_optional_tag(&mut tags, "runtime_version", &PHP_VERSION.get());
                add_optional_tag(&mut tags, "php.sapi", &SAPI.get());

                locals.tags = Arc::new(tags);
            }

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count as *const AtomicU32,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                if let Err((index, interrupt)) = profiler.add_interrupt(interrupt) {
                    warn!("VM interrupt {interrupt} already exists at offset {index}");
                }
            }
        });
    }

    #[cfg(feature = "allocation_profiling")]
    {
        if profiling_experimental_allocation_enabled {
            if !is_zend_mm() {
                // Neighboring custom memory handlers found
                debug!("Found another extension using the ZendMM custom handler hook");
                unsafe {
                    zend::zend_mm_get_custom_handlers(
                        zend::zend_mm_get_heap(),
                        &mut PREV_CUSTOM_MM_ALLOC,
                        &mut PREV_CUSTOM_MM_FREE,
                        &mut PREV_CUSTOM_MM_REALLOC,
                    );
                }
            }

            unsafe {
                zend::ddog_php_prof_zend_mm_set_custom_handlers(
                    zend::zend_mm_get_heap(),
                    Some(alloc_profiling_malloc),
                    Some(alloc_profiling_free),
                    Some(alloc_profiling_realloc),
                );
            }

            if is_zend_mm() {
                error!("Memory allocation profiling could not be enabled. Please feel free to fill an issue stating the PHP version and installed modules. Most likely the reason is your PHP binary was compiled with `ZEND_MM_CUSTOM` being disabled.");
                REQUEST_LOCALS.with(|cell| {
                    let mut locals = cell.borrow_mut();
                    locals.profiling_experimental_allocation_enabled = false;
                });
            } else {
                info!("Memory allocation profiling enabled.")
            }
        }
    }

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

fn detect_uri_from_config(
    url: Option<Cow<'static, str>>,
    host: Option<Cow<'static, str>>,
    port: Option<u16>,
) -> AgentEndpoint {
    /* Priority:
     *  1. DD_TRACE_AGENT_URL
     *     - RFC allows unix:///path/to/some/socket so parse these out.
     *     - Maybe emit diagnostic if an invalid URL is detected or the path is non-existent, but
     *       continue down the priority list.
     *  2. DD_AGENT_HOST and/or DD_TRACE_AGENT_PORT. If only one is set, default the other.
     *  3. Unix Domain Socket at /var/run/datadog/apm.socket
     *  4. http://localhost:8126
     */
    if let Some(trace_agent_url) = url {
        // check for UDS first
        if let Some(path) = trace_agent_url.strip_prefix("unix://") {
            let path = PathBuf::from(path);
            if path.exists() {
                return AgentEndpoint::Socket(path);
            } else {
                warn!(
                    "Unix socket specified in DD_TRACE_AGENT_URL does not exist: {} ",
                    path.to_string_lossy()
                );
            }
        } else {
            match Uri::from_str(trace_agent_url.as_ref()) {
                Ok(uri) => return AgentEndpoint::Uri(uri),
                Err(err) => warn!("DD_TRACE_AGENT_URL was not a valid URL: {err}"),
            }
        }
        // continue down priority list
    }
    if port.is_some() || host.is_some() {
        let host = host.unwrap_or(Cow::Borrowed("localhost"));
        let port = port.unwrap_or(8126u16);
        let url = if host.contains(':') {
            format!("http://[{host}]:{port}")
        } else {
            format!("http://{host}:{port}")
        };

        match Uri::from_str(url.as_str()) {
            Ok(uri) => return AgentEndpoint::Uri(uri),
            Err(err) => {
                warn!("The combination of DD_AGENT_HOST({host}) and DD_TRACE_AGENT_PORT({port}) was not a valid URL: {err}")
            }
        }
        // continue down priority list
    }

    AgentEndpoint::default()
}

extern "C" fn rshutdown(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("RSHUTDOWN({}, {})", r#type, module_number);

    #[cfg(php8)]
    {
        profiling::FUNCTION_CACHE_STATS.with(|cell| {
            let stats = cell.borrow();
            let hit_rate = stats.hit_rate();
            debug!("Process cumulative {stats:?} hit_rate: {hit_rate}");
        });
    }

    REQUEST_LOCALS.with(|cell| {
        let mut locals = cell.borrow_mut();

        if locals.profiling_enabled {
            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                if let Err(err) = profiler.remove_interrupt(interrupt) {
                    warn!("Unable to find interrupt {err}.");
                }
            }
            locals.tags = Arc::new(static_tags());
        }

        #[cfg(feature = "allocation_profiling")]
        {
            if locals.profiling_experimental_allocation_enabled {
                // If `is_zend_mm()` is true, the custom handlers have been reset to `None`
                // already. This is unexpected, therefore we will not touch the ZendMM handlers
                // anymore as resetting to prev handlers might result in segfaults and other
                // undefined behaviour.
                if !is_zend_mm() {
                    let mut custom_mm_malloc: Option<zend::VmMmCustomAllocFn> = None;
                    let mut custom_mm_free: Option<zend::VmMmCustomFreeFn> = None;
                    let mut custom_mm_realloc: Option<zend::VmMmCustomReallocFn> = None;
                    unsafe {
                        zend::zend_mm_get_custom_handlers(
                            zend::zend_mm_get_heap(),
                            &mut custom_mm_malloc,
                            &mut custom_mm_free,
                            &mut custom_mm_realloc,
                        );
                    }
                    if custom_mm_free != Some(alloc_profiling_free)
                        || custom_mm_malloc != Some(alloc_profiling_malloc)
                        || custom_mm_realloc != Some(alloc_profiling_realloc)
                    {
                        // Custom handlers are installed, but it's not us. Someone, somewhere might have
                        // function pointers to our custom handlers. Best bet to avoid segfaults is to not
                        // touch custom handlers in ZendMM and make sure our extension will not be
                        // `dlclose()`-ed so the pointers stay valid
                        let zend_extension = unsafe {
                            zend::zend_get_extension(PROFILER_NAME.as_ptr() as *const c_char)
                        };
                        if !zend_extension.is_null() {
                            // Safety: Checked for null pointer above.
                            unsafe {
                                (*zend_extension).handle = std::ptr::null_mut();
                            }
                        }
                        // disable any further allocation profiling
                        locals.profiling_experimental_allocation_enabled = false;
                        info!("Memory allocation profiling disabled.");
                    } else {
                        // This is the happy path (restore previously installed custom handlers)!
                        unsafe {
                            zend::ddog_php_prof_zend_mm_set_custom_handlers(
                                zend::zend_mm_get_heap(),
                                PREV_CUSTOM_MM_ALLOC,
                                PREV_CUSTOM_MM_FREE,
                                PREV_CUSTOM_MM_REALLOC,
                            );
                        }
                    }
                }
            }
        }
    });

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
        let yes: &[u8] = b"true\0";
        let no: &[u8] = b"false\0";
        zend::php_info_print_table_start();
        zend::php_info_print_table_row(2, b"Version\0".as_ptr(), module.version);
        zend::php_info_print_table_row(
            2,
            b"Profiling Enabled\0".as_ptr(),
            if locals.profiling_enabled { yes } else { no },
        );

        zend::php_info_print_table_row(
            2,
            b"Experimental CPU Time Profiling Enabled\0".as_ptr(),
            if locals.profiling_experimental_cpu_time_enabled {
                yes
            } else {
                no
            },
        );

        zend::php_info_print_table_row(
            2,
            b"Experimental Allocation Profiling Enabled\0".as_ptr(),
            if locals.profiling_experimental_allocation_enabled {
                yes
            } else {
                no
            },
        );

        zend::php_info_print_table_row(
            2,
            b"Endpoint Collection Enabled\0".as_ptr(),
            if locals.profiling_endpoint_collection_enabled {
                yes
            } else {
                no
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

        let mut log_level = format!("{}\0", locals.profiling_log_level);
        log_level.make_ascii_lowercase();
        zend::php_info_print_table_row(2, b"Profiling Log Level\0".as_ptr(), log_level.as_ptr());

        let key = b"Profiling Agent Endpoint\0".as_ptr();
        let agent_endpoint = format!("{}\0", locals.uri);
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

extern "C" fn mshutdown(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("MSHUTDOWN({}, {})", r#type, module_number);

    unsafe { bindings::zai_config_mshutdown() };

    let profiler = PROFILER.lock().unwrap();
    if let Some(profiler) = profiler.as_ref() {
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

    #[cfg(php8)]
    // Safety: calling this in startup/minit as required.
    unsafe {
        bindings::ddog_php_prof_function_run_time_cache_init(PROFILER_NAME_CSTR.as_ptr())
    };

    // Ignore a failure as ZEND_VERSION.get() will return an Option if it's not set.
    let _ = ZEND_VERSION.get_or_try_init(|| {
        // Safety: CStr string is null-terminated without any interior null bytes.
        let module_name = unsafe { CStr::from_bytes_with_nul_unchecked(b"Core\0") };
        get_module_version(module_name).ok_or(())
    });

    // Ignore a failure as PHP_VERSION.get() will return an Option if it's not set.
    let _ = PHP_VERSION.get_or_try_init(|| {
        // Reflection uses the PHP_VERSION as its version, see:
        // https://github.com/php/php-src/blob/PHP-8.1.4/ext/reflection/php_reflection.h#L25
        // https://github.com/php/php-src/blob/PHP-8.1.4/ext/reflection/php_reflection.c#L7157
        // It goes back to at least PHP 7.1:
        // https://github.com/php/php-src/blob/PHP-7.1/ext/reflection/php_reflection.h

        // Safety: CStr string is null-terminated without any interior null bytes.
        let module_name = unsafe { CStr::from_bytes_with_nul_unchecked(b"Reflection\0") };
        get_module_version(module_name).ok_or(())
    });

    // Safety: calling this in zend_extension startup.
    unsafe { pcntl::startup() };

    #[cfg(feature = "allocation_profiling")]
    unsafe {
        let handle = datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"gc_mem_caches\0"),
            &mut GC_MEM_CACHES_HANDLER,
            Some(alloc_profiling_gc_mem_caches),
        );
        datadog_php_install_handler(handle);
    }

    ZendResult::Success
}

extern "C" fn shutdown(_extension: *mut ZendExtension) {
    #[cfg(debug_assertions)]
    trace!("shutdown({:p})", _extension);

    let mut profiler = PROFILER.lock().unwrap();
    if let Some(profiler) = profiler.take() {
        profiler.shutdown(Duration::from_secs(2));
    }
}

/// Notifies the profiler a trace has finished so it can update information
/// for Endpoint Profiling.
fn notify_trace_finished(local_root_span_id: u64, span_type: Cow<str>, resource: Cow<str>) {
    REQUEST_LOCALS.with(|cell| {
        let locals = cell.borrow();
        if locals.profiling_enabled && locals.profiling_endpoint_collection_enabled {
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

/// Gathers a time sample if the configured period has elapsed and resets the
/// interrupt_count.
fn interrupt_function(execute_data: *mut zend::zend_execute_data) {
    REQUEST_LOCALS.with(|cell| {
        let mut locals = cell.borrow_mut();
        if !locals.profiling_enabled {
            return;
        }

        /* Other extensions/modules or the engine itself may trigger an
         * interrupt, but given how expensive it is to gather a stack trace,
         * it should only be done if we triggered it ourselves. So
         * interrupt_count serves dual purposes:
         *  1. Track how many interrupts there were.
         *  2. Ensure we don't collect on someone else's interrupt.
         */
        let interrupt_count = locals.interrupt_count.swap(0, Ordering::SeqCst);
        if interrupt_count == 0 {
            return;
        }

        if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
            // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
            unsafe { profiler.collect_time(execute_data, interrupt_count, locals.deref_mut()) };
        }
    });
}

/// A wrapper for the `datadog_profiling_interrupt_function` to call the
/// previous interrupt handler, if there was one.
extern "C" fn interrupt_function_wrapper(execute_data: *mut zend::zend_execute_data) {
    interrupt_function(execute_data);

    // Safety: PREV_INTERRUPT_FUNCTION was written during minit, doesn't change during runtime.
    unsafe {
        if let Some(prev_interrupt) = *PREV_INTERRUPT_FUNCTION.as_mut_ptr() {
            prev_interrupt(execute_data);
        }
    }
}

/// Overrides the engine's zend_execute_internal hook in order to process pending VM interrupts
/// while the internal function is still on top of the call stack. The VM does not process the
/// interrupt until the call returns so that it could theoretically jump to a different opcode,
/// like a fiber scheduler.
/// For our particular case this is problematic. For example, when the user does something like
/// `sleep(seconds: 10)`, the normal interrupt handling will not trigger until sleep returns, so
/// we'd then attribute all that time spent sleeping to whatever runs next. This is why we intercept
/// `zend_execute_internal` and process our own VM interrupts, but it doesn't delegate to the
/// previous VM interrupt hook, as it's not expecting to be called from this state.
extern "C" fn execute_internal(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    // Safety: PREV_EXECUTE_INTERNAL was written during minit, doesn't change during runtime.
    unsafe {
        let prev_execute_internal = *PREV_EXECUTE_INTERNAL.as_mut_ptr();
        prev_execute_internal(execute_data, return_value);
    }
    interrupt_function(execute_data);
}

/// Overrides the ZendMM heap's `use_custom_heap` flag with the default `ZEND_MM_CUSTOM_HEAP_NONE`
/// (currently a `u32: 0`). This needs to be done, as the `zend_mm_gc()` and `zend_mm_shutdown()`
/// functions alter behaviour in case custom handlers are installed.
/// - `zend_mm_gc()` will not do anything anymore.
/// - `zend_mm_shutdown()` wont cleanup chunks anymore, leading to memory leaks
/// The `_zend_mm_heap`-struct itself is private, but we are lucky, as the `use_custom_heap` flag
/// is the first element and thus the first 4 bytes.
/// Take care and call `restore_zend_heap()` afterwards!
#[cfg(feature = "allocation_profiling")]
unsafe fn prepare_zend_heap(heap: *mut zend::_zend_mm_heap) -> c_int {
    let custom_heap: c_int = std::ptr::read(heap as *const c_int);
    std::ptr::write(heap as *mut c_int, zend::ZEND_MM_CUSTOM_HEAP_NONE as c_int);
    custom_heap
}

/// Restore the ZendMM heap's `use_custom_heap` flag, see `prepare_zend_heap` for details
#[cfg(feature = "allocation_profiling")]
unsafe fn restore_zend_heap(heap: *mut zend::_zend_mm_heap, custom_heap: c_int) {
    std::ptr::write(heap as *mut c_int, custom_heap);
}

#[cfg(feature = "allocation_profiling")]
unsafe extern "C" fn alloc_profiling_gc_mem_caches(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    let allocation_profiling: bool = REQUEST_LOCALS.with(|cell| {
        // Panic: there might already be a mutable reference to `REQUEST_LOCALS`
        let locals = cell.try_borrow();
        if locals.is_err() {
            // we can't check and don't know so assume it is not activated
            return false;
        }
        let locals = locals.unwrap();
        locals.profiling_experimental_allocation_enabled
    });

    if let Some(func) = GC_MEM_CACHES_HANDLER {
        if allocation_profiling {
            let heap = zend::zend_mm_get_heap();
            let custom_heap = prepare_zend_heap(heap);
            func(execute_data, return_value);
            restore_zend_heap(heap, custom_heap);
        } else {
            func(execute_data, return_value);
        }
    } else {
        ddog_php_prof_copy_long_into_zval(return_value, 0);
    }
}

#[cfg(feature = "allocation_profiling")]
unsafe extern "C" fn alloc_profiling_malloc(len: u64) -> *mut ::libc::c_void {
    let ptr: *mut libc::c_void;

    // TODO: prepare a function pointer to use so we don't need a runtime check
    if PREV_CUSTOM_MM_ALLOC.is_none() {
        let heap = zend::zend_mm_get_heap();
        let custom_heap = prepare_zend_heap(heap);
        ptr = zend::_zend_mm_alloc(heap, len);
        restore_zend_heap(heap, custom_heap);
    } else {
        let prev = PREV_CUSTOM_MM_ALLOC.unwrap();
        ptr = prev(len);
    }

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() {
        return ptr;
    }

    ALLOCATION_PROFILING_STATS.with(|cell| {
        let mut allocations = cell.borrow_mut();
        allocations.track_allocation(len)
    });

    ptr
}

// The reason this function exists is because when calling `zend_mm_set_custom_handlers()` you need
// to pass a pointer to a `free()` function as well, otherwise your custom handlers won't be
// installed. We can not just point to the original `zend::_zend_mm_free()` as the function
// definitions differ.
#[cfg(feature = "allocation_profiling")]
unsafe extern "C" fn alloc_profiling_free(ptr: *mut ::libc::c_void) {
    if PREV_CUSTOM_MM_FREE.is_none() {
        let heap = zend::zend_mm_get_heap();
        zend::_zend_mm_free(heap, ptr);
    } else {
        let prev = PREV_CUSTOM_MM_FREE.unwrap();
        prev(ptr);
    }
}

#[cfg(feature = "allocation_profiling")]
unsafe extern "C" fn alloc_profiling_realloc(
    prev_ptr: *mut ::libc::c_void,
    len: u64,
) -> *mut ::libc::c_void {
    let ptr: *mut libc::c_void;
    if PREV_CUSTOM_MM_REALLOC.is_none() {
        let heap = zend::zend_mm_get_heap();
        let custom_heap = prepare_zend_heap(heap);
        ptr = zend::_zend_mm_realloc(heap, prev_ptr, len);
        restore_zend_heap(heap, custom_heap);
    } else {
        let prev = PREV_CUSTOM_MM_REALLOC.unwrap();
        ptr = prev(prev_ptr, len);
    }

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() || ptr == prev_ptr {
        return ptr;
    }

    ALLOCATION_PROFILING_STATS.with(|cell| {
        let mut allocations = cell.borrow_mut();
        allocations.track_allocation(len)
    });

    ptr
}

/// safe wrapper for `zend::is_zend_mm()`.
/// `true` means the internal ZendMM is being used, `false` means that a custom memory manager is
/// installed. Upstream returns a `c_bool` as of PHP 8.0. PHP 7 returns a `c_int`
#[cfg(feature = "allocation_profiling")]
fn is_zend_mm() -> bool {
    #[cfg(php7)]
    {
        unsafe { zend::is_zend_mm() == 1 }
    }
    #[cfg(php8)]
    {
        unsafe { zend::is_zend_mm() }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn detect_uri_from_config_works() {
        // expected
        let endpoint = detect_uri_from_config(None, None, None);
        let expected = AgentEndpoint::default();
        assert_eq!(endpoint, expected);

        // ipv4 host
        let endpoint = detect_uri_from_config(None, Some(Cow::Owned("127.0.0.1".to_owned())), None);
        let expected = AgentEndpoint::Uri(Uri::from_static("http://127.0.0.1:8126"));
        assert_eq!(endpoint, expected);

        // ipv6 host
        let endpoint = detect_uri_from_config(None, Some(Cow::Owned("::1".to_owned())), None);
        let expected = AgentEndpoint::Uri(Uri::from_static("http://[::1]:8126"));
        assert_eq!(endpoint, expected);

        // ipv6 host, custom port
        let endpoint = detect_uri_from_config(None, Some(Cow::Owned("::1".to_owned())), Some(9000));
        let expected = AgentEndpoint::Uri(Uri::from_static("http://[::1]:9000"));
        assert_eq!(endpoint, expected);

        // agent_url
        let endpoint =
            detect_uri_from_config(Some(Cow::Owned("http://[::1]:8126".to_owned())), None, None);
        let expected = AgentEndpoint::Uri(Uri::from_static("http://[::1]:8126"));
        assert_eq!(endpoint, expected);

        // fallback on non existing UDS
        let endpoint = detect_uri_from_config(
            Some(Cow::Owned("unix://foo/bar/baz/I/do/not/exist".to_owned())),
            None,
            None,
        );
        let expected = AgentEndpoint::default();
        assert_eq!(endpoint, expected);
    }
}
