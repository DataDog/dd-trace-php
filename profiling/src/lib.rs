mod bindings;
mod logging;
mod profiling;
mod sapi;

use crate::profiling::Profiler;
use bindings as zend;
use bindings::{
    datadog_php_str, sapi_getenv, DatadogPhpProfilingGlobals, ZendExtension, ZendResult,
};
use ddprof::exporter::{Tag, Uri};
use lazy_static::lazy_static;
use libc::{c_char, c_int, c_ulong, c_void};
use log::{debug, error, info, trace, warn, LevelFilter};
use once_cell::sync::OnceCell;
use sapi::Sapi;
use std::cell::{RefCell, RefMut};
use std::ffi::CStr;
use std::mem::MaybeUninit;
use std::ops::DerefMut;
use std::str::FromStr;
use std::sync::atomic::Ordering;
use std::sync::{Mutex, Once};
use std::time::Instant;

/// The version of PHP at runtime, not the version compiled against. Sent as
/// a profile tag.
static PHP_VERSION: OnceCell<String> = OnceCell::new();

lazy_static! {
    /// The global profiler. In Rust 1.63+, Mutex::new is const and this can be
    /// made a regular global instead of a lazy_static one.
    static ref PROFILER: Mutex<Option<Profiler>> = Mutex::new(None);
}

/// Name of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_NAME: &[u8] = b"datadog-profiling\0";

/// Version of the profiling module and zend_extension. Must not contain any
/// interior null bytes and must be null terminated.
static PROFILER_VERSION: &[u8] = b"0.7.0\0";

/// The runtime ID, which is basically a universally unique "pid", so it can
/// change on minit, as well as theoretically on fork. It should only be
/// changed from thread-safe contexts.
/// todo: support forking.
static mut RUNTIME_ID: profiling::Uuid = profiling::Uuid::new();

/// The Server API the profiler is running under.
static SAPI: OnceCell<Sapi> = OnceCell::new();

/// The version of the ZendEngine at runtime, not the version compiled against.
/// It's currently unused, but I hope to send it as a metric or something soon
/// so I'm keeping it here so I don't have to look up how to get it again.
static ZEND_VERSION: OnceCell<String> = OnceCell::new();

#[no_mangle]
pub extern "C" fn get_module() -> &'static mut zend::ModuleEntry {
    /* In PHP modules written in C, this just returns the address of a global,
     * mutable variable. In Rust, you cannot initialize such a complicated
     * global variable because of initialization order issues that have been
     * found through decades of C++ experience.
     * So, borrow an initialization pattern from Rust itself:
     * https://github.com/rust-lang/rust/blob/2a8cb678e61e91c160d80794b5fdd723d0d4211c/src/libstd/io/stdio.rs#L217-L247
     */
    static mut MODULE: MaybeUninit<zend::ModuleEntry> = MaybeUninit::uninit();

    static DEPS: [zend::ModuleDep; 2] = [
        zend::ModuleDep::optional(b"ddtrace\0"),
        zend::ModuleDep::end(),
    ];

    static ONCE: Once = Once::new();

    unsafe {
        ONCE.call_once(|| {
            let mut module = zend::ModuleEntry {
                name: PROFILER_NAME.as_ptr(),
                module_startup_func: Some(minit),
                module_shutdown_func: Some(mshutdown),
                request_startup_func: Some(rinit),
                request_shutdown_func: Some(rshutdown),
                info_func: Some(minfo),
                version: PROFILER_VERSION.as_ptr(),
                globals_size: std::mem::size_of::<DatadogPhpProfilingGlobals>() as u64,
                globals_ctor: Some(ginit),
                globals_dtor: Some(gshutdown),
                post_deactivate_func: Some(prshutdown),
                deps: DEPS.as_ptr(),
                ..Default::default()
            };
            #[cfg(php_zts)]
            {
                // todo: zts
                module.globals_id_ptr = &mut zend::datadog_php_profiling_globals_id as *mut c_void;
            }
            #[cfg(not(php_zts))]
            {
                module.globals_ptr = zend::datadog_php_profiling_globals_get()
                    as *mut zend::zend_datadog_php_profiling_globals
                    as *mut c_void;
            }

            MODULE.write(module);
        });

        &mut *MODULE.as_mut_ptr()
    }
}

static mut PREV_INTERRUPT_FUNCTION: MaybeUninit<Option<zend::VmInterruptFn>> =
    MaybeUninit::uninit();
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
unsafe extern "C" fn minit(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("MINIT({}, {})", r#type, module_number);

    // Ignore unused result; use SAPI.get() which returns an Option if it's uninitialized.
    let _ = SAPI.get_or_try_init(|| {
        let sapi_module = zend::sapi_module;
        if !sapi_module.name.is_null() {
            let sapi_name = CStr::from_ptr(sapi_module.name);
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
        let ptr = zend::datadog_get_module_entry(str, len);
        if ptr.is_null() {
            error!("Unable to locate our own module in the engine registry.");
            return ZendResult::Failure;
        }

        // Safety: checked nullability above; engine did the rest.
        let module = &mut *ptr;
        let handle = module.handle;
        module.handle = std::ptr::null_mut();
        handle
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

    PREV_INTERRUPT_FUNCTION.write(zend::zend_interrupt_function);
    zend::zend_interrupt_function = Some(if zend::zend_interrupt_function.is_some() {
        interrupt_function_wrapper
    } else {
        interrupt_function
    });

    PREV_EXECUTE_INTERNAL.write(zend::zend_execute_internal.unwrap_or(zend::execute_internal));
    zend::zend_execute_internal = Some(execute_internal);

    zend::zend_register_extension(&extension, handle);

    // Set up runtime-id for Code Hotspots
    RUNTIME_ID = profiling::Uuid::from(uuid::Uuid::new_v4());

    ZendResult::Success
}

extern "C" fn ginit(global: *mut c_void) {
    /* When developing the extension, it's useful to see log messages that
     * occur before the user can configure the log level. However, if we
     * initialized the logger here unconditionally then they'd have no way to
     * hide these messages. That's why it's done only for debug builds.
     */
    #[cfg(debug_assertions)]
    {
        logging::log_init(LevelFilter::Trace);
        trace!("GINIT({:p})", global);
    }

    /* Safety: The engine has allocated the globals to the right size and
     * alignment, so it is safe to dereference this, but only when cast to
     * MaybeUninit, as it's uninitialized (which is the point of this hook).
     */
    unsafe {
        let global = global as *mut MaybeUninit<DatadogPhpProfilingGlobals>;
        (*global).write(DatadogPhpProfilingGlobals::default());
    }
}

unsafe extern "C" fn gshutdown(_global: *mut c_void) {
    #[cfg(debug_assertions)]
    trace!("GSHUTDOWN({:p})", _global);
}

extern "C" fn prshutdown() -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("PRSHUTDOWN");

    ZendResult::Success
}

/// # Safety
/// Only call during an active request from the PHP thread!
unsafe fn getenv(name: &CStr) -> Option<String> {
    // CStr doesn't have a len() so turn it into a slice.
    let name = name.to_bytes();

    let val = sapi_getenv(name.as_ptr() as *const c_char, name.len() as c_ulong);
    let val = val.into_string();
    if !val.is_empty() {
        return Some(val);
    }

    // If the sapi didn't have an env var, try the libc.
    let val = libc::getenv(name.as_ptr() as *const c_char);
    if val.is_null() {
        return None;
    }
    let val = CStr::from_ptr(val);
    return Some(String::from_utf8_lossy(val.to_bytes()).into_owned());
}

fn parse_boolean(mut string: String) -> Option<bool> {
    // no boolean strings are longer than 5 characters
    if string.is_empty() || string.len() > 5 {
        return None;
    }

    string.make_ascii_lowercase();
    match string.as_str() {
        "1" | "on" | "yes" | "true" => Some(true),
        "0" | "no" | "off" | "false" => Some(false),
        _ => None,
    }
}

/// Intern the string for the duration of a request.
/// # Safety
/// Do not use the string for more than the request lifetime!
unsafe fn intern(string: &Option<String>) -> datadog_php_str {
    if let Some(val) = string {
        if !val.is_empty() {
            return zend::datadog_php_profiling_intern(
                val.as_ptr() as *const c_char,
                val.len() as c_ulong,
                false,
            );
        }
    }
    datadog_php_str::default()
}

/// # Safety
/// Must be called exactly once during first rinit!
unsafe fn read_env(globals: &mut zend::zend_datadog_php_profiling_globals) {
    // We don't handle INIs yet -- we store the env vars on first rinit
    let profiling_enabled = getenv(CStr::from_bytes_with_nul_unchecked(
        b"DD_PROFILING_ENABLED\0",
    ));
    let profiling_log_level = getenv(CStr::from_bytes_with_nul_unchecked(
        b"DD_PROFILING_LOG_LEVEL\0",
    ));
    let profiling_experimental_cpu_time_enabled = getenv(CStr::from_bytes_with_nul_unchecked(
        b"DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED\0",
    ));

    let env = getenv(CStr::from_bytes_with_nul_unchecked(b"DD_ENV\0"));
    let service = getenv(CStr::from_bytes_with_nul_unchecked(b"DD_SERVICE\0"));
    let version = getenv(CStr::from_bytes_with_nul_unchecked(b"DD_VERSION\0"));
    let agent_host = getenv(CStr::from_bytes_with_nul_unchecked(b"DD_AGENT_HOST\0"));
    let trace_agent_port = getenv(CStr::from_bytes_with_nul_unchecked(
        b"DD_TRACE_AGENT_PORT\0",
    ));
    let trace_agent_url = getenv(CStr::from_bytes_with_nul_unchecked(b"DD_TRACE_AGENT_URL\0"));

    globals.profiling_enabled = profiling_enabled.and_then(parse_boolean).unwrap_or(false);

    globals.profiling_experimental_cpu_time_enabled = profiling_experimental_cpu_time_enabled
        .and_then(parse_boolean)
        .unwrap_or(false);

    let profiling_log_level = profiling_log_level
        .and_then(|string| {
            let string = string.to_ascii_uppercase();
            LevelFilter::from_str(string.as_str()).ok()
        })
        .unwrap_or(LevelFilter::Off);

    globals.profiling_log_level = std::mem::transmute(profiling_log_level);
    globals.env = intern(&env);
    globals.service = intern(&service);
    globals.version = intern(&version);
    globals.agent_host = intern(&agent_host);
    globals.trace_agent_port = intern(&trace_agent_port);
    globals.trace_agent_url = intern(&trace_agent_url);
}

pub struct RequestLocals {
    pub last_wall_time: Instant,
    pub last_cpu_time: Option<cpu_time::ThreadTime>,
    pub tags: Vec<Tag>,
    pub uri: String,
}

fn static_tags() -> Vec<Tag> {
    vec![
        Tag::from_value("language:php").expect("static tags to be valid"),
        // Safety: calling getpid() is safe.
        Tag::new("process_id", unsafe { libc::getpid() }.to_string())
            .expect("static tags to be valid"),
    ]
}

thread_local! {
    static REQUEST_LOCALS: RefCell<RequestLocals> = RefCell::new(RequestLocals {
        last_wall_time: Instant::now(),
        last_cpu_time: None,
        tags: static_tags(),
        uri: String::from("http://localhost:8126"),
    });
}

/* If Failure is returned the VM will do a C exit; try hard to avoid that,
 * using it for catastrophic errors only.
 */
extern "C" fn rinit(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("RINIT({}, {})", r#type, module_number);

    unsafe { zend::datadog_php_profiling_rinit() };

    // SAFETY: globals pointer is valid during rinit.
    let globals = unsafe { zend::datadog_php_profiling_globals_get() };
    unsafe { read_env(globals) };

    // At the moment, logging is truly global, so init it exactly once whether
    // profiling is enabled or not.
    static ONCE: Once = Once::new();
    ONCE.call_once(|| {
        #[cfg(not(debug_assertions))]
        logging::log_init(unsafe { std::mem::transmute(globals.profiling_log_level) });
        #[cfg(debug_assertions)]
        log::set_max_level(globals.profiling_log_level);

        if globals.profiling_enabled {
            /* Safety: sapi_module is initialized by rinit and shouldn't be
             * modified at this point (safe to read values).
             */
            let sapi_module = unsafe { &zend::sapi_module };
            if sapi_module.pretty_name.is_null() {
                let name = unsafe { CStr::from_ptr(sapi_module.name) }.to_string_lossy();
                warn!("The SAPI module {}'s pretty name was not set!", name)
            } else {
                let pretty_name =
                    unsafe { CStr::from_ptr(sapi_module.pretty_name) }.to_string_lossy();
                if SAPI.get().unwrap_or(&Sapi::Unknown) != &Sapi::Unknown {
                    debug!("Recognized SAPI: {}.", pretty_name);
                } else {
                    warn!("Unrecognized SAPI: {}.", pretty_name);
                }
            }
            if let Err(err) = cpu_time::ThreadTime::try_now() {
                if globals.profiling_experimental_cpu_time_enabled {
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
            } else if globals.profiling_experimental_cpu_time_enabled {
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

    if globals.profiling_enabled {
        unsafe {
            REQUEST_LOCALS.with(|cell| {
                let mut locals = cell.borrow_mut();

                locals.last_wall_time = Instant::now();
                if globals.profiling_experimental_cpu_time_enabled {
                    let now = cpu_time::ThreadTime::try_now()
                        .expect("CPU time to work since it's worked before during this process");
                    locals.last_cpu_time = Some(now);
                }

                let vars = [
                    ("service", globals.service, "unnamed-php-service"),
                    ("env", globals.env, ""),
                    ("version", globals.version, ""),
                ];

                for (key, value, default_value) in vars {
                    if value.size > 0 && !value.ptr.is_null() {
                        let result: Result<&str, _> = (&value).try_into();
                        if let Ok(value) = result {
                            log_add_tag(&mut locals, key, value);
                        }
                    } else if !default_value.is_empty() {
                        log_add_tag(&mut locals, key, default_value);
                    }
                }

                let runtime_id: uuid::Uuid = RUNTIME_ID.into();
                if !runtime_id.is_nil() {
                    match Tag::new("runtime-id", runtime_id.to_string().as_str()) {
                        Ok(tag) => {
                            locals.tags.push(tag);
                        }
                        Err(err) => {
                            warn!("invalid tag: {}", err);
                        }
                    }
                }

                match detect_url_from_globals(globals) {
                    Ok(url) => {
                        locals.uri = url.to_string();
                    }
                    Err(err) => {
                        error!("Failed to identify HTTP url: {}", err);
                    }
                };
            });

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.add_interrupt(globals.vm_interrupt_addr, &globals.interrupt_count);
            }
        }
    }
    ZendResult::Success
}

fn log_add_tag(locals: &mut RefMut<RequestLocals>, key: &str, value: &str) {
    match Tag::new(key, value) {
        Ok(tag) => {
            locals.tags.push(tag);
        }
        Err(err) => {
            warn!("invalid tag: {}", err);
        }
    }
}

fn detect_url_from_globals(globals: &DatadogPhpProfilingGlobals) -> anyhow::Result<Uri> {
    if globals.trace_agent_url.size > 0 {
        let maybe_uri: &str = (&globals.trace_agent_url).try_into()?;
        Ok(Uri::from_str(maybe_uri)?)
    } else if globals.trace_agent_port.size > 0 || globals.agent_host.size > 0 {
        let host: &str = if globals.agent_host.size > 0 {
            (&globals.agent_host).try_into()?
        } else {
            "localhost"
        };
        let port: &str = if globals.trace_agent_port.size > 0 {
            (&globals.trace_agent_port).try_into()?
        } else {
            "8126"
        };
        Ok(Uri::from_str(format!("http://{}:{}", host, port).as_str())?)
    } else {
        Ok(Uri::from_str("http://localhost:8126")?)
    }
}

extern "C" fn rshutdown(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("RSHUTDOWN({}, {})", r#type, module_number);

    // SAFETY: globals pointer is valid during rshutdown.
    let globals = unsafe { zend::datadog_php_profiling_globals_get() };
    if globals.profiling_enabled {
        if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
            profiler.remove_interrupt(globals.vm_interrupt_addr, &globals.interrupt_count);
        }

        REQUEST_LOCALS.with(|cell| {
            let mut locals = cell.borrow_mut();
            locals.tags = static_tags();
        });
    }

    ZendResult::Success
}

unsafe fn stop_profiling() {
    let mut profiler = PROFILER.lock().unwrap();
    if let Some(profiler) = profiler.take() {
        profiler.stop();
    }
}

unsafe extern "C" fn minfo(module: *mut zend::ModuleEntry) {
    #[cfg(debug_assertions)]
    trace!("MINFO({:p})", module);

    let module = &*module;
    // SAFETY: TODO: is globals pointer valid during minfo?
    let globals = zend::datadog_php_profiling_globals_get();

    let yes: &[u8] = b"true\0";
    let no: &[u8] = b"false\0";
    zend::php_info_print_table_start();
    zend::php_info_print_table_row(2, b"Version\0".as_ptr(), module.version);
    zend::php_info_print_table_row(
        2,
        b"Profiling Enabled\0".as_ptr(),
        if globals.profiling_enabled { yes } else { no },
    );

    zend::php_info_print_table_row(
        2,
        b"Experimental CPU Time Profiling Enabled\0".as_ptr(),
        if globals.profiling_experimental_cpu_time_enabled {
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

    let mut log_level = format!("{}\0", globals.profiling_log_level);
    log_level.make_ascii_lowercase();
    zend::php_info_print_table_row(2, b"Profiling Log Level\0".as_ptr(), log_level.as_ptr());

    let endpoint = match detect_url_from_globals(globals) {
        Ok(url) => format!("{}\0", url),
        Err(_) => "{{error detecting endpoint}}\0".to_string(),
    };
    zend::php_info_print_table_row(2, b"Profiling Agent Endpoint\0".as_ptr(), endpoint.as_ptr());

    let vars = [
        (b"Application's Environment (DD_ENV)\0", globals.env.ptr),
        (b"Application's Service (DD_SERVICE)\0", globals.service.ptr),
        (b"Application's Version (DD_VERSION)\0", globals.version.ptr),
    ];

    for (key, value) in vars {
        zend::php_info_print_table_row(2, key, value);
    }

    zend::php_info_print_table_end();
}

unsafe extern "C" fn mshutdown(r#type: c_int, module_number: c_int) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("MSHUTDOWN({}, {})", r#type, module_number);

    stop_profiling();

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

unsafe extern "C" fn startup(extension: *mut ZendExtension) -> ZendResult {
    #[cfg(debug_assertions)]
    trace!("startup({:p})", extension);

    zend::datadog_php_profiling_startup(extension);

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
        let module_name = CStr::from_bytes_with_nul_unchecked(b"Reflection\0");
        get_module_version(module_name).ok_or(())
    });

    ZendResult::Success
}

extern "C" fn shutdown(_extension: *mut ZendExtension) {
    #[cfg(debug_assertions)]
    trace!("shutdown({:p})", _extension);
}

/// # Safety
/// This is safe to call except that it cannot be called concurrently with
/// MINIT. This _should_ be guaranteed by the PHP request model.
#[no_mangle]
pub unsafe extern "C" fn datadog_profiling_runtime_id() -> profiling::Uuid {
    RUNTIME_ID
}

/// Used internally to gather time samples when the configured period has
/// elapsed. Also used by the tracer to handle pending profiler interrupts
/// before calling a tracing closure from an internal function hook; if this
/// isn't done then the closure is erroneously at the top of the stack.
///
/// # Safety
/// The zend_execute_data pointer should come from the engine to ensure it and
/// its sub-objects are valid.
#[export_name = "datadog_profiling_interrupt_function"]
pub unsafe extern "C" fn interrupt_function(execute_data: *mut zend::zend_execute_data) {
    // SAFETY: globals pointer is valid during interrupt handler.
    let globals = zend::datadog_php_profiling_globals_get();
    if !globals.profiling_enabled {
        return;
    }

    /* Other extensions/modules or the engine itself may trigger an interrupt,
     * but given how expensive it is to gather a stack trace, it should only
     * be done if we triggered it ourselves. So interrupt_count serves dual
     * purposes 1) to track how many interrupts there were and 2) to ensure we
     * don't collect on someone else's interrupt.
     */
    let interrupt_count = globals.interrupt_count.swap(0, Ordering::SeqCst);
    if interrupt_count == 0 {
        return;
    }

    if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
        REQUEST_LOCALS.with(|cell| {
            // borrow locals mutably to adjust time
            profiler.collect_time(execute_data, interrupt_count, cell.borrow_mut().deref_mut());
        });
    }
}

/// A wrapper for the `interrupt_function` to call the previous interrupt handler, if there was one.
unsafe extern "C" fn interrupt_function_wrapper(execute_data: *mut zend::zend_execute_data) {
    interrupt_function(execute_data);

    if let Some(prev_interrupt) = *PREV_INTERRUPT_FUNCTION.as_mut_ptr() {
        prev_interrupt(execute_data);
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
unsafe extern "C" fn execute_internal(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    let prev_execute_internal = *PREV_EXECUTE_INTERNAL.as_mut_ptr();
    prev_execute_internal(execute_data, return_value);
    interrupt_function(execute_data);
}

#[cfg(test)]
mod tests {
    extern "C" {
        fn test_datadog_php_profiling_log_level_parse_success() -> bool;
    }

    #[test]
    fn test_log_level_parsing() {
        assert!(unsafe { test_datadog_php_profiling_log_level_parse_success() });
    }
}
