mod bindings;
pub mod capi;
mod config;
mod logging;
mod pcntl;
mod profiling;
mod sapi;

use crate::profiling::{Profiler, VmInterrupt};
use bindings as zend;
use bindings::{sapi_getenv, ZendExtension, ZendResult};
use config::AgentEndpoint;
use datadog_profiling::exporter::{Tag, Uri};
use lazy_static::lazy_static;
use libc::c_int;
use log::{debug, error, info, trace, warn, LevelFilter};
use once_cell::sync::OnceCell;
use sapi::Sapi;
use std::borrow::Cow;
use std::cell::{RefCell, RefMut};
use std::ffi::CStr;
use std::mem::MaybeUninit;
use std::ops::DerefMut;
use std::path::PathBuf;
use std::str::FromStr;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Mutex, Once};
use std::time::Instant;
use uuid::Uuid;

/// The version of PHP at runtime, not the version compiled against. Sent as
/// a profile tag.
static PHP_VERSION: OnceCell<String> = OnceCell::new();

lazy_static! {
    /// The global profiler. Profiler gets made during the first rinit after
    /// an rinit, and is destroyed on mshutdown.
    /// In Rust 1.63+, Mutex::new is const and this can be made a regular
    /// global instead of a lazy_static one.
    static ref PROFILER: Mutex<Option<Profiler>> = Mutex::new(None);
}

/// Name of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_NAME: &[u8] = b"datadog-profiling\0";

/// Version of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_VERSION: &[u8] = concat!(env!("CARGO_PKG_VERSION"), "\0").as_bytes();

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
         * let _ = datadog_profiling::exporter::initialize_before_fork();
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
        let str = PROFILER_NAME.as_ptr();
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

    ZendResult::Success
}

fn parse_boolean(string: &str) -> Option<bool> {
    /* Care was taken to avoid allocating and to not do more than 2 calls to
     * `eq_ignore_ascii_case` as this function gets called 2+ times on every
     * single request that comes in. Not particularly performance sensitive,
     * but it adds directly to latency of the end-user, and we also want to
     * avoid allocator churn when we can.
     */

    let n_bytes = string.len();
    // no boolean strings are longer than 5 ASCII characters
    if n_bytes == 0 || n_bytes > 5 {
        return None;
    }

    /* The lookup tables are based on the number of bytes in the string. The
     * empty string will not match since it was handled above.
     */
    const TRUTH_TABLE: [&str; 5] = ["1", "on", "yes", "true", ""];
    const FALSE_TABLE: [&str; 5] = ["0", "no", "off", "", "false"];

    let offset = n_bytes - 1;
    if TRUTH_TABLE[offset].eq_ignore_ascii_case(string) {
        Some(true)
    } else if FALSE_TABLE[offset].eq_ignore_ascii_case(string) {
        Some(false)
    } else {
        None
    }
}

pub struct RequestLocals {
    pub env: Option<String>,
    pub interrupt_count: AtomicU32,
    pub last_cpu_time: Option<cpu_time::ThreadTime>,
    pub last_wall_time: Instant,
    pub profiling_enabled: bool,
    pub profiling_endpoint_collection_enabled: bool,
    pub profiling_experimental_cpu_time_enabled: bool,
    pub profiling_log_level: LevelFilter, // Only used for minfo
    pub service: Option<String>,
    pub tags: Vec<Tag>,
    pub uri: Box<AgentEndpoint>,
    pub version: Option<String>,
    pub vm_interrupt_addr: *const AtomicBool,
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
        profiling_log_level: LevelFilter::Off,
        service: None,
        tags: static_tags(),
        uri: Box::new(AgentEndpoint::default()),
        version: None,
        vm_interrupt_addr: std::ptr::null_mut(),
    });
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

    // initialize the thread local storage and cache some items
    let (log_level, profiling_enabled, profiling_experimental_cpu_time_enabled) = REQUEST_LOCALS
        .with(|cell| {
            let mut locals = cell.borrow_mut();
            // Safety: called during rinit.
            let environment = unsafe { config::Env::get() };
            locals.uri = Box::new(detect_uri_from_env(&environment));
            locals.env = environment.env;
            locals.profiling_enabled = environment
                .profiling_enabled
                .as_deref()
                .and_then(parse_boolean)
                .unwrap_or(false);
            locals.profiling_endpoint_collection_enabled = environment
                .profiling_endpoint_collection_enabled
                .as_deref()
                .and_then(parse_boolean)
                .unwrap_or(true);
            locals.profiling_experimental_cpu_time_enabled = environment
                .profiling_experimental_cpu_time_enabled
                .as_deref()
                .or(environment.profiling_experimental_cpu_enabled.as_deref())
                .and_then(parse_boolean)
                .unwrap_or(true);
            locals.service = environment.service.or_else(|| {
                SAPI.get().and_then(|sapi| match sapi {
                    Sapi::Cli => {
                        // Safety: sapi globals are safe to access during rinit
                        sapi.request_script_name(unsafe { &zend::sapi_globals })
                            .or_else(|| Some(String::from("cli.command")))
                    }
                    _ => Some(String::from("web.request")),
                })
            });
            locals.version = environment.version;

            // Safety: we are in rinit on a PHP thread.
            locals.vm_interrupt_addr = unsafe { zend::datadog_php_profiling_vm_interrupt_addr() };

            locals.profiling_log_level = environment
                .profiling_log_level
                .as_deref()
                .and_then(|str| LevelFilter::from_str(str).ok())
                .unwrap_or(LevelFilter::Off);

            (
                locals.profiling_log_level,
                locals.profiling_enabled,
                locals.profiling_experimental_cpu_time_enabled,
            )
        });

    /* At the moment, logging is truly global, so init it exactly once whether
     * profiling is enabled or not.
     */
    static ONCE: Once = Once::new();
    ONCE.call_once(|| {
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
                warn!("The SAPI module {}'s pretty name was not set!", name)
            } else {
                // Safety: I'm willing to bet the module pretty name is less than `isize::MAX`.
                let pretty_name =
                    unsafe { CStr::from_ptr(sapi_module.pretty_name) }.to_string_lossy();
                if SAPI.get().unwrap_or(&Sapi::Unknown) != &Sapi::Unknown {
                    debug!("Recognized SAPI: {}.", pretty_name);
                } else {
                    warn!("Unrecognized SAPI: {}.", pretty_name);
                }
            }
            if let Err(err) = cpu_time::ThreadTime::try_now() {
                if profiling_experimental_cpu_time_enabled {
                    warn!(
                        "CPU Time collection was enabled but collection failed: {}",
                        err
                    );
                } else {
                    debug!(
                        "CPU Time collection was not enabled and isn't available: {}",
                        err
                    );
                }
            } else if profiling_experimental_cpu_time_enabled {
                info!("CPU Time profiling enabled.");
            }
        }
    });

    // reminder: this cannot be done in minit because of Apache forking model
    {
        /* It would be nice if this could be cheaper. OnceCell would be cheaper
         * but it doesn't quite fit the model, as going back to uninitialized
         * requires either a &mut or .take(), and neither works for us (unless
         * we go for unsafe, which is what we are trying to avoid).
         */
        let mut profiler = PROFILER.lock().unwrap();
        if profiler.is_none() {
            *profiler = Some(Profiler::new())
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

            // I can't figure out how to make the borrow checker happy without copying here :/
            let vars = [
                ("service", locals.service.clone(), "unnamed-php-service"),
                ("env", locals.env.clone(), ""),
                ("version", locals.version.clone(), ""),
            ];

            for (key, value, default_value) in vars {
                if let Some(value) = value {
                    // the value should be non-empty here
                    log_add_tag(&mut locals, key, value.into());
                } else if !default_value.is_empty() {
                    log_add_tag(&mut locals, key, default_value.into());
                }
            }

            let runtime_id = runtime_id();
            if !runtime_id.is_nil() {
                match Tag::new("runtime-id", runtime_id.to_string()) {
                    Ok(tag) => {
                        locals.tags.push(tag);
                    }
                    Err(err) => {
                        warn!("invalid tag: {}", err);
                    }
                }
            }

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count as *const AtomicU32,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                if let Err((index, interrupt)) = profiler.add_interrupt(interrupt) {
                    warn!(
                        "VM interrupt {} already exists at offset {}",
                        index, interrupt
                    );
                }
            }
        });
    }
    ZendResult::Success
}

fn log_add_tag(locals: &mut RefMut<RequestLocals>, key: &str, value: Cow<'static, str>) {
    match Tag::new(key, value) {
        Ok(tag) => {
            locals.tags.push(tag);
        }
        Err(err) => {
            warn!("invalid tag: {}", err);
        }
    }
}

fn detect_uri_from_env(env: &config::Env) -> AgentEndpoint {
    /* Priority:
     *  1. DD_TRACE_AGENT_URL
     *     - RFC allows unix:///path/to/some/socket so parse these out.
     *     - Maybe emit diagnostic if an invalid URL is detected or the path is non-existent, but
     *       continue down the priority list.
     *  2. DD_AGENT_HOST and/or DD_TRACE_AGENT_PORT. If only one is set, default the other.
     *  3. Unix Domain Socket at /var/run/datadog/apm.socket
     *  4. http://localhost:8126
     */
    if let Some(trace_agent_url) = &env.trace_agent_url {
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
            match Uri::from_str(trace_agent_url) {
                Ok(uri) => return AgentEndpoint::Uri(uri),
                Err(err) => warn!("DD_TRACE_AGENT_URL was not a valid URL: {}", err),
            }
        }
        // continue down priority list
    }
    if env.trace_agent_port.is_some() || env.agent_host.is_some() {
        let host = env.agent_host.as_deref().unwrap_or("localhost");
        let port = env.trace_agent_port.as_deref().unwrap_or("8126");

        match Uri::from_str(format!("http://{}:{}", host, port).as_str()) {
            Ok(uri) => return AgentEndpoint::Uri(uri),
            Err(err) => {
                warn!("The combination of DD_AGENT_HOST ({}) and DD_TRACE_AGENT_PORT ({}) was not a valid URL: {}", host, port, err)
            }
        }
        // continue down priority list
    }

    AgentEndpoint::default()
}

extern "C" fn rshutdown(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("RSHUTDOWN({}, {})", r#type, module_number);

    REQUEST_LOCALS.with(|cell| {
        let mut locals = cell.borrow_mut();
        if locals.profiling_enabled {
            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                let interrupt = VmInterrupt {
                    interrupt_count_ptr: &locals.interrupt_count,
                    engine_ptr: locals.vm_interrupt_addr,
                };
                if let Err(err) = profiler.remove_interrupt(interrupt) {
                    warn!("Unable to find interrupt {}.", err);
                }
            }
            locals.tags = static_tags();
        }
    });

    ZendResult::Success
}

/// Prints the module info. Calls many C functions from the Zend Engine,
/// including calling variadic functions. It's essentially all unsafe, so be
/// careful, and do not call this manually (only let the engine call it).
unsafe extern "C" fn minfo(module: *mut zend::ModuleEntry) {
    #[cfg(debug_assertions)]
    trace!("MINFO({:p})", module);

    let module = &*module;

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
            b"Endpoint Profiling Enabled\0".as_ptr(),
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
            let mut value = value.clone().unwrap_or_default();
            value.push('\0');
            zend::php_info_print_table_row(2, key, value.as_ptr());
        }

        zend::php_info_print_table_end();
    });
}

extern "C" fn mshutdown(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("MSHUTDOWN({}, {})", r#type, module_number);

    let mut profiler = PROFILER.lock().unwrap();
    if let Some(profiler) = profiler.take() {
        profiler.stop();
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

    ZendResult::Success
}

extern "C" fn shutdown(_extension: *mut ZendExtension) {
    #[cfg(debug_assertions)]
    trace!("shutdown({:p})", _extension);
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
                    "Local root span id {} ended but did not have a span type of 'web' (actual: '{}'), so Endpoint Profiling data will not be sent.",
                    local_root_span_id, span_type
                );
                return;
            }

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                if let Err(err) = profiler.send_local_root_span_resource(local_root_span_id, resource) {
                    warn!("Failed to enqueue endpoint profiling information: {}.", err);
                } else {
                    trace!(
                        "Enqueued endpoint profiling information for span id: {}.",
                        local_root_span_id
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_parse_boolean() {
        let truthies = [
            "1", "on", "yes", "true", "On", "Yes", "True", "ON", "YES", "TRUE",
        ];
        for val in truthies {
            assert_eq!(Some(true), parse_boolean(val), "Testing {}", val);
        }

        let falsies = [
            "0", "no", "off", "false", "No", "Off", "False", "NO", "OFF", "FALSE",
        ];
        for val in falsies {
            assert_eq!(Some(false), parse_boolean(val), "Testing {}", val);
        }

        let non_boolean = ["", "a", "2", "-1", "truuue", "💯", "🦀!", "🔥🔥"];
        for val in non_boolean {
            let actual = parse_boolean(val);
            assert_eq!(None, actual, "Testing {}", val);
        }
    }
}
