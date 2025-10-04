use crate::profiling::{extract_function_name, Profiler};
use crate::sapi::Sapi;
use crate::zend::{
    self, zai_str_from_zstr, zend_execute_data, zend_get_executed_filename_ex, zval,
    InternalFunctionHandler,
};
use crate::{RefCellExt, REQUEST_LOCALS, SAPI};
use ddcommon::cstr;
use libc::c_char;
use log::{error, trace};
#[cfg(php_zts)]
use std::cell::Cell;
use std::cell::RefCell;
use std::ptr;
use std::time::Instant;
use std::time::SystemTime;
use std::time::UNIX_EPOCH;

/// The engine's original (or neighbouring extensions) `gc_collect_cycles()` function
static mut PREV_GC_COLLECT_CYCLES: Option<zend::VmGcCollectCyclesFn> = None;

/// The engine's original (or neighbouring extensions) `zend_compile_string()` function
static mut PREV_ZEND_COMPILE_STRING: Option<zend::VmZendCompileString> = None;

/// The engine's original (or neighbouring extensions) `zend_compile_file()` function
static mut PREV_ZEND_COMPILE_FILE: Option<zend::VmZendCompileFile> = None;

static mut PREV_FRANKEN_PHP_SAPI_ACTIVATE: Option<unsafe extern "C" fn() -> i32> = None;
static mut PREV_FRANKEN_PHP_SAPI_DEACTIVATE: Option<unsafe extern "C" fn() -> i32> = None;

/// The engine's original (or neighbouring extensions) `zend_accel_schedule_restart_hook()`
/// function
#[cfg(php_opcache_restart_hook)]
static mut PREV_ZEND_ACCEL_SCHEDULE_RESTART_HOOK: Option<zend::VmZendAccelScheduleRestartHook> =
    None;

thread_local! {
    static IDLE_SINCE: RefCell<Instant> = RefCell::new(Instant::now());
    #[cfg(php_zts)]
    static IS_NEW_THREAD: Cell<bool> = const { Cell::new(false) };
}

pub enum State {
    Idle,
    Sleeping,
    Select,
    #[cfg(php_zts)]
    ThreadStart,
    #[cfg(php_zts)]
    ThreadStop,
}

impl State {
    pub fn as_str(&self) -> &'static str {
        match self {
            State::Idle => "idle",
            State::Sleeping => "sleeping",
            State::Select => "select",
            #[cfg(php_zts)]
            State::ThreadStart => "thread start",
            #[cfg(php_zts)]
            State::ThreadStop => "thread stop",
        }
    }
}

fn is_in_frankenphp_handle_request(execute_data: *mut zend_execute_data) -> bool {
    let Some(execute_data) = (unsafe { execute_data.as_ref() }) else {
        return false;
    };
    let Some(func) = (unsafe { execute_data.func.as_ref() }) else {
        return false;
    };
    let Some(func) = extract_function_name(func) else {
        return false;
    };
    func == "frankenphp|frankenphp_handle_request"
}

extern "C" fn frankenphp_sapi_module_activate() -> i32 {
    let timeline_enabled = REQUEST_LOCALS
        .borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled);

    if timeline_enabled
        && is_in_frankenphp_handle_request(unsafe {
            zend::ddog_php_prof_get_current_execute_data()
        })
    {
        // SAFETY: guarded by timeline_enabled check.
        unsafe { timeline_idle_stop() };
    }

    unsafe {
        if let Some(activate) = PREV_FRANKEN_PHP_SAPI_ACTIVATE {
            return activate();
        }
    }

    0
}

extern "C" fn frankenphp_sapi_module_deactivate() -> i32 {
    let timeline_enabled = REQUEST_LOCALS
        .borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled);

    if timeline_enabled
        && is_in_frankenphp_handle_request(unsafe {
            zend::ddog_php_prof_get_current_execute_data()
        })
    {
        timeline_idle_start();
    }

    unsafe {
        if let Some(deactivate) = PREV_FRANKEN_PHP_SAPI_DEACTIVATE {
            return deactivate();
        }
    }

    0
}

fn sleeping_fn(
    func: unsafe extern "C" fn(execute_data: *mut zend_execute_data, return_value: *mut zval),
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
    state: State,
) {
    if !REQUEST_LOCALS.borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
    {
        unsafe { func(execute_data, return_value) };
        return;
    }

    let start = Instant::now();

    // Consciously not holding request locals/profiler during the forwarded
    // call. If they are, then it's possible to get a deadlock/bad borrow
    // because the call triggers something to happen like a time/allocation
    // sample and the extension tries to re-acquire these.
    // SAFETY: simple forwarding to original func with original args.
    unsafe { func(execute_data, return_value) };

    let duration = start.elapsed();

    // This shouldn't ever happen (now is always later than the epoch)
    let now = SystemTime::now().duration_since(UNIX_EPOCH);

    if now.is_err() {
        return;
    }

    if let Some(profiler) = Profiler::get() {
        // Safety: `unwrap` can be unchecked, as we checked for `is_err()`
        let now = unsafe { now.unwrap_unchecked().as_nanos() } as i64;
        let duration = duration.as_nanos() as i64;
        profiler.collect_idle(now, duration, state);
    }
}

macro_rules! create_sleeping_fn {
    ($fn_name:ident, $handler:ident, $state:expr) => {
        static mut $handler: InternalFunctionHandler = None;

        #[no_mangle]
        unsafe extern "C" fn $fn_name(
            execute_data: *mut zend_execute_data,
            return_value: *mut zval,
        ) {
            if let Some(func) = $handler {
                sleeping_fn(func, execute_data, return_value, $state)
            }
        }
    };
}

// Functions that are sleeping
create_sleeping_fn!(ddog_php_prof_sleep, SLEEP_HANDLER, State::Sleeping);
create_sleeping_fn!(ddog_php_prof_usleep, USLEEP_HANDLER, State::Sleeping);
create_sleeping_fn!(
    ddog_php_prof_time_nanosleep,
    TIME_NANOSLEEP_HANDLER,
    State::Sleeping
);
create_sleeping_fn!(
    ddog_php_prof_time_sleep_until,
    TIME_SLEEP_UNTIL_HANDLER,
    State::Sleeping
);

// Functions that are blocking on I/O
create_sleeping_fn!(
    ddog_php_prof_stream_select,
    STREAM_SELECT_HANDLER,
    State::Select
);
create_sleeping_fn!(
    ddog_php_prof_socket_select,
    SOCKET_SELECT_HANDLER,
    State::Select
);
create_sleeping_fn!(
    ddog_php_prof_curl_multi_select,
    CURL_MULTI_SELECT_HANDLER,
    State::Select
);
create_sleeping_fn!(ddog_php_prof_uv_run, UV_RUN_HANDLER, State::Select);
create_sleeping_fn!(
    ddog_php_prof_event_base_loop,
    EVENT_BASE_LOOP_HANDLER,
    State::Select
);
create_sleeping_fn!(
    ddog_php_prof_eventbase_loop,
    EVENTBASE_LOOP_HANDLER,
    State::Select
);
create_sleeping_fn!(
    ddog_php_prof_ev_loop_run,
    EV_LOOP_RUN_HANDLER,
    State::Select
);
create_sleeping_fn!(
    ddog_php_prof_parallel_events_poll,
    PARALLEL_EVENTS_POLL_HANDLER,
    State::Select
);

/// Will be called by the ZendEngine on all errors happening. This is a PHP 8 API
#[cfg(zend_error_observer)]
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_zend_error_observer(
    _type: i32,
    #[cfg(zend_error_observer_80)] file: *const c_char,
    #[cfg(not(zend_error_observer_80))] file: *mut zend::ZendString,
    line: u32,
    message: *mut zend::ZendString,
) {
    // we are only interested in FATAL errors

    if _type & zend::E_FATAL_ERRORS as i32 == 0 {
        return;
    }

    if !REQUEST_LOCALS.borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
    {
        return;
    }

    #[cfg(zend_error_observer_80)]
    let filename_str = unsafe { core::ffi::CStr::from_ptr(file) };
    #[cfg(not(zend_error_observer_80))]
    let filename_str = unsafe { zai_str_from_zstr(file.as_mut()) };

    let filename = filename_str.to_string_lossy().into_owned();

    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap();
    if let Some(profiler) = Profiler::get() {
        let now = now.as_nanos() as i64;
        profiler.collect_fatal(now, filename, line, unsafe {
            zend::zai_str_from_zstr(message.as_mut()).into_string()
        });
    }
}

/// Will be called by the opcache extension when a restart is scheduled. The `reason` is this enum:
/// ```C
/// typedef enum _zend_accel_restart_reason {
///     ACCEL_RESTART_OOM,    /* restart because of out of memory */
///     ACCEL_RESTART_HASH,   /* restart because of hash overflow */
///     ACCEL_RESTART_USER    /* restart scheduled by opcache_reset() */
/// } zend_accel_restart_reason;
/// ```
#[no_mangle]
#[cfg(php_opcache_restart_hook)]
unsafe extern "C" fn ddog_php_prof_zend_accel_schedule_restart_hook(reason: i32) {
    if REQUEST_LOCALS.borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
    {
        let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap();
        if let Some(profiler) = Profiler::get() {
            let now = now.as_nanos() as i64;
            let file = unsafe {
                zend::zai_str_from_zstr(zend::zend_get_executed_filename_ex().as_mut())
                    .into_string()
            };
            profiler.collect_opcache_restart(
                now,
                file,
                zend::zend_get_executed_lineno(),
                match reason {
                    0 => "out of memory",
                    1 => "hash overflow",
                    2 => "`opcache_restart()` called",
                    _ => "unknown",
                },
            );
        }
    }

    if let Some(prev) = PREV_ZEND_ACCEL_SCHEDULE_RESTART_HOOK {
        prev(reason);
    }
}

/// This functions needs to be called in MINIT of the module
pub fn timeline_minit() {
    unsafe {
        #[cfg(zend_error_observer)]
        zend::zend_observer_error_register(Some(ddog_php_prof_zend_error_observer));

        #[cfg(php_opcache_restart_hook)]
        {
            PREV_ZEND_ACCEL_SCHEDULE_RESTART_HOOK = zend::zend_accel_schedule_restart_hook;
            zend::zend_accel_schedule_restart_hook =
                Some(ddog_php_prof_zend_accel_schedule_restart_hook);
        }

        // register our function in the `gc_collect_cycles` pointer
        PREV_GC_COLLECT_CYCLES = zend::gc_collect_cycles;
        zend::gc_collect_cycles = Some(ddog_php_prof_gc_collect_cycles);

        // register our function in the `zend_compile_file` pointer
        PREV_ZEND_COMPILE_FILE = zend::zend_compile_file;
        zend::zend_compile_file = Some(ddog_php_prof_compile_file);

        // register our function in the `zend_compile_string` pointer
        PREV_ZEND_COMPILE_STRING = zend::zend_compile_string;
        zend::zend_compile_string = Some(ddog_php_prof_compile_string);

        // To detect idle phases in FrankenPHP's worker mode, we hook the `sapi_module.activate` /
        // `sapi_module.deactivate` function pointers, as FrankenPHP's worker call those via the
        // `sapi_activate()` / `sapi_deactivate()` function calls from
        // `frankenphp_worker_request_startup` / `frankenphp_worker_request_shutdown`. There is no
        // special handling for the first idle phase needed, as FrankenPHP will initiate a dummy
        // request upon startup of a FrankenPHP worker and run to the first
        // `frankenphp_handle_request()` PHP function from which onward we'll collect the first idle
        // phase.
        if *SAPI == Sapi::FrankenPHP {
            PREV_FRANKEN_PHP_SAPI_ACTIVATE = zend::sapi_module.activate;
            PREV_FRANKEN_PHP_SAPI_DEACTIVATE = zend::sapi_module.deactivate;
            zend::sapi_module.activate = Some(frankenphp_sapi_module_activate);
            zend::sapi_module.deactivate = Some(frankenphp_sapi_module_deactivate);
        }
    }
}

/// This function is run during the STARTUP phase and hooks into the execution of some functions
/// that we'd like to observe in regards of visualization on the timeline
pub unsafe fn timeline_startup() {
    let handlers = [
        zend::datadog_php_zif_handler::new(
            cstr!("sleep"),
            ptr::addr_of_mut!(SLEEP_HANDLER),
            Some(ddog_php_prof_sleep),
        ),
        zend::datadog_php_zif_handler::new(
            cstr!("usleep"),
            ptr::addr_of_mut!(USLEEP_HANDLER),
            Some(ddog_php_prof_usleep),
        ),
        zend::datadog_php_zif_handler::new(
            cstr!("time_nanosleep"),
            ptr::addr_of_mut!(TIME_NANOSLEEP_HANDLER),
            Some(ddog_php_prof_time_nanosleep),
        ),
        zend::datadog_php_zif_handler::new(
            cstr!("time_sleep_until"),
            ptr::addr_of_mut!(TIME_SLEEP_UNTIL_HANDLER),
            Some(ddog_php_prof_time_sleep_until),
        ),
        zend::datadog_php_zif_handler::new(
            cstr!("stream_select"),
            ptr::addr_of_mut!(STREAM_SELECT_HANDLER),
            Some(ddog_php_prof_stream_select),
        ),
        zend::datadog_php_zif_handler::new(
            cstr!("socket_select"),
            ptr::addr_of_mut!(SOCKET_SELECT_HANDLER),
            Some(ddog_php_prof_socket_select),
        ),
        zend::datadog_php_zif_handler::new(
            cstr!("curl_multi_select"),
            ptr::addr_of_mut!(CURL_MULTI_SELECT_HANDLER),
            Some(ddog_php_prof_curl_multi_select),
        ),
        // provided by `ext-uv` from https://pecl.php.net/package/uv
        zend::datadog_php_zif_handler::new(
            cstr!("uv_run"),
            ptr::addr_of_mut!(UV_RUN_HANDLER),
            Some(ddog_php_prof_uv_run),
        ),
        // provided by `ext-libevent` from https://pecl.php.net/package/libevent
        zend::datadog_php_zif_handler::new(
            cstr!("event_base_loop"),
            ptr::addr_of_mut!(EVENT_BASE_LOOP_HANDLER),
            Some(ddog_php_prof_event_base_loop),
        ),
    ];

    for handler in handlers.into_iter() {
        // Safety: we've set all the parameters correctly for this C call.
        zend::datadog_php_install_handler(handler);
    }

    let handlers = [
        // provided by `ext-ev` from https://pecl.php.net/package/ev
        zend::datadog_php_zim_handler::new(
            cstr!("evloop"),
            cstr!("run"),
            ptr::addr_of_mut!(EV_LOOP_RUN_HANDLER),
            Some(ddog_php_prof_ev_loop_run),
        ),
        // provided by `ext-event` from https://pecl.php.net/package/event
        zend::datadog_php_zim_handler::new(
            cstr!("eventbase"),
            cstr!("loop"),
            ptr::addr_of_mut!(EVENTBASE_LOOP_HANDLER),
            Some(ddog_php_prof_eventbase_loop),
        ),
        // provided by `ext-parallel` from https://pecl.php.net/package/parallel
        zend::datadog_php_zim_handler::new(
            cstr!("parallel\\events"),
            cstr!("poll"),
            ptr::addr_of_mut!(PARALLEL_EVENTS_POLL_HANDLER),
            Some(ddog_php_prof_parallel_events_poll),
        ),
    ];

    for handler in handlers.into_iter() {
        // Safety: we've set all the parameters correctly for this C call.
        zend::datadog_php_install_method_handler(handler);
    }
}

/// Ends the idle period for timeline.
///
/// # Safety
///
/// Timeline must be enabled. Most callers will naturally do this anyway.
unsafe fn timeline_idle_stop() {
    IDLE_SINCE.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(idle_since) = cell.try_borrow() else {
            return;
        };

        if let Some(profiler) = Profiler::get() {
            profiler.collect_idle(
                // Safety: checked for `is_err()` above
                SystemTime::now()
                    .duration_since(UNIX_EPOCH)
                    .unwrap()
                    .as_nanos() as i64,
                idle_since.elapsed().as_nanos() as i64,
                State::Idle,
            );
        }
    });
}

fn timeline_idle_start() {
    // If we can't borrow, not much we can do.
    _ = IDLE_SINCE.try_with_borrow_mut(|idle_since| {
        *idle_since = Instant::now();
    });
}

/// This function is run during the RINIT phase and reports any `IDLE_SINCE` duration as an idle
/// period for this PHP thread.
/// # SAFETY
/// Must be called only in rinit and after [crate::config::first_rinit].
pub unsafe fn timeline_rinit() {
    let timeline_enabled = REQUEST_LOCALS
        .borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled);
    if !timeline_enabled {
        return;
    }

    // SAFETY: guarded by timeline_enabled check.
    unsafe { timeline_idle_stop() };

    #[cfg(php_zts)]
    IS_NEW_THREAD.with(|cell| {
        if !cell.get() {
            return;
        }
        cell.set(false);

        if let Some(profiler) = Profiler::get() {
            profiler.collect_thread_start_end(
                // Safety: checked for `is_err()` above
                SystemTime::now()
                    .duration_since(UNIX_EPOCH)
                    .unwrap()
                    .as_nanos() as i64,
                State::ThreadStart,
            );
        }
    });
}

/// This function is run during the P-RSHUTDOWN phase and resets the `IDLE_SINCE` thread local to
/// "now", indicating the start of a new idle phase
pub fn timeline_prshutdown() {
    if !REQUEST_LOCALS.borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
    {
        return;
    }

    timeline_idle_start();
}

/// This function is run during the MSHUTDOWN phase and reports any `IDLE_SINCE` duration as an idle
/// period for this PHP thread. This will report the last `IDLE_SINCE` duration created in the last
/// `P-RSHUTDOWN` (just above) when the PHP process is shutting down.
/// # Saftey
/// Must be called in shutdown before [crate::config::shutdown].
pub(crate) fn timeline_mshutdown() {
    let timeline_enabled = REQUEST_LOCALS
        .borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled);
    if timeline_enabled {
        // SAFETY: guarded by timeline_enabled check.
        unsafe { timeline_idle_stop() };
    }

    // Unhook `sapi_module.activate` / `sapi_module.deactivate` in case SAPI is FrankenPHP. This
    // hook was installed in `timeline_minit`
    if *SAPI == Sapi::FrankenPHP {
        unsafe {
            zend::sapi_module.activate = PREV_FRANKEN_PHP_SAPI_ACTIVATE;
            zend::sapi_module.deactivate = PREV_FRANKEN_PHP_SAPI_DEACTIVATE;
            PREV_FRANKEN_PHP_SAPI_ACTIVATE = None;
            PREV_FRANKEN_PHP_SAPI_DEACTIVATE = None;
        }
    }

    #[cfg(php_zts)]
    timeline_gshutdown();
}

#[cfg(php_zts)]
pub(crate) fn timeline_ginit() {
    // During GINIT in "this" thread, the request locals are not initialized, which happens in
    // RINIT, so we currently do not know if profile is enabled at all and if, if timeline is
    // enabled. That's why we raise this flag here and read it in RINIT.
    IS_NEW_THREAD.set(true);
}

#[cfg(php_zts)]
pub(crate) fn timeline_gshutdown() {
    if !REQUEST_LOCALS.borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
    {
        return;
    }

    if let Some(profiler) = Profiler::get() {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
            .min(i64::MAX as u128) as i64;
        profiler.collect_thread_start_end(
            // Safety: checked for `is_err()` above
            now,
            State::ThreadStop,
        );
    }
}

/// This function gets called when a `eval()` is being called. This is done by letting the
/// `zend_compile_string` function pointer point to this function.
/// When called, we call the previous function and measure the wall-time it took to compile the
/// given string which will then be reported to the profiler.
unsafe extern "C" fn ddog_php_prof_compile_string(
    #[cfg(php7)] source_string: *mut zend::_zval_struct,
    #[cfg(php8)] source_string: *mut zend::ZendString,
    #[cfg(php7)] filename: *mut c_char,
    #[cfg(php8)] filename: *const c_char,
    #[cfg(php_zend_compile_string_has_position)] position: zend::zend_compile_position,
) -> *mut zend::_zend_op_array {
    if let Some(prev) = PREV_ZEND_COMPILE_STRING {
        if !REQUEST_LOCALS
            .borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
        {
            #[cfg(php_zend_compile_string_has_position)]
            return prev(source_string, filename, position);
            #[cfg(not(php_zend_compile_string_has_position))]
            return prev(source_string, filename);
        }

        let start = Instant::now();
        #[cfg(php_zend_compile_string_has_position)]
        let op_array = prev(source_string, filename, position);
        #[cfg(not(php_zend_compile_string_has_position))]
        let op_array = prev(source_string, filename);
        let duration = start.elapsed();
        let now = SystemTime::now().duration_since(UNIX_EPOCH);

        // eval() failed
        // TODO we might collect this event anyway and label it accordingly in a later stage of
        // this feature
        if op_array.is_null() || now.is_err() {
            return op_array;
        }

        let filename = zai_str_from_zstr(zend_get_executed_filename_ex().as_mut()).into_string();

        let line = zend::zend_get_executed_lineno();

        trace!(
            "Compiling eval()'ed code in \"{filename}\" at line {line} took {} nanoseconds",
            duration.as_nanos(),
        );

        if let Some(profiler) = Profiler::get() {
            profiler.collect_compile_string(
                // Safety: checked for `is_err()` above
                now.unwrap().as_nanos() as i64,
                duration.as_nanos() as i64,
                filename,
                line,
            );
        }
        return op_array;
    }
    error!("No previous `zend_compile_string` handler found! This is a huge problem as your eval() won't work and PHP will higly likely crash. I am sorry, but the die is cast.");
    ptr::null_mut()
}

/// This function gets called when a file is `include()`ed/`require()`d. This is done by letting
/// the `zend_compile_file` function pointer point to this function.
/// When called, we call the previous function and measure the wall-time it took to compile the
/// given file which will then be reported to the profiler.
unsafe extern "C" fn ddog_php_prof_compile_file(
    handle: *mut zend::zend_file_handle,
    r#type: i32,
) -> *mut zend::_zend_op_array {
    if let Some(prev) = PREV_ZEND_COMPILE_FILE {
        if !REQUEST_LOCALS
            .borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
        {
            return prev(handle, r#type);
        }

        let start = Instant::now();
        let op_array = prev(handle, r#type);
        let duration = start.elapsed();
        let now = SystemTime::now().duration_since(UNIX_EPOCH);

        // include/require failed, could be invalid PHP in file or file not found, or time went
        // backwards
        // TODO we might collect this event anyway and label it accordingly in a later stage of
        // this feature
        if op_array.is_null() || (*op_array).filename.is_null() || now.is_err() {
            return op_array;
        }

        let include_type = match r#type as u32 {
            zend::ZEND_INCLUDE => "include", // `include_once()` and `include_once()`
            zend::ZEND_REQUIRE => "require", // `require()` and `require_once()`
            _default => "",
        };

        // Extract the filename from the returned op_array.
        // We could also extract from the handle, but those filenames might be different from
        // the one in the `op_array`: In the handle we get what `include()` was called with,
        // for example "/var/www/html/../vendor/foo/bar.php" while during stack walking we get
        // "/var/html/vendor/foo/bar.php". This makes sure it is the exact same string we'd
        // collect in stack walking and therefore we are fully utilizing the pprof string table
        let filename = zai_str_from_zstr((*op_array).filename.as_mut()).into_string();

        trace!(
            "Compile file \"{filename}\" with include type \"{include_type}\" took {} nanoseconds",
            duration.as_nanos(),
        );

        if let Some(profiler) = Profiler::get() {
            profiler.collect_compile_file(
                // Safety: checked for `is_err()` above
                now.unwrap().as_nanos() as i64,
                duration.as_nanos() as i64,
                filename,
                r#type,
            );
        }
        return op_array;
    }
    error!("No previous `zend_compile_file` handler found! This is a huge problem as your include()/require() won't work and PHP will higly likely crash. I am sorry, but the die is cast.");
    ptr::null_mut()
}

/// Find out the reason for the current garbage collection cycle. If there is
/// a `gc_collect_cycles` function at the top of the call stack, it is because
/// of a userland call  to `gc_collect_cycles()`, otherwise the engine decided
/// to run it.
unsafe fn gc_reason() -> &'static str {
    let execute_data = zend::ddog_php_prof_get_current_execute_data();
    let fname = || execute_data.as_ref()?.func.as_ref()?.name();
    match fname() {
        Some(name) if name == b"gc_collect_cycles" => "induced",
        _ => "engine",
    }
}

/// This function gets called whenever PHP does a garbage collection cycle instead of the original
/// handler. This is done by letting the `zend::gc_collect_cycles` pointer point to this function
/// and store the previous pointer in `PREV_GC_COLLECT_CYCLES` for later use.
/// When called, we do collect the time the call to the `PREV_GC_COLLECT_CYCLES` took and report
/// this to the profiler.
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_gc_collect_cycles() -> i32 {
    if let Some(prev) = PREV_GC_COLLECT_CYCLES {
        if !REQUEST_LOCALS
            .borrow_or_false(|locals| locals.system_settings().profiling_timeline_enabled)
        {
            return prev();
        }

        #[cfg(php_gc_status)]
        let mut status = core::mem::MaybeUninit::<zend::zend_gc_status>::uninit();

        let start = Instant::now();
        let collected = prev();
        let duration = start.elapsed();
        let now = SystemTime::now().duration_since(UNIX_EPOCH);
        if now.is_err() {
            // time went backwards
            return collected;
        }

        let reason = gc_reason();

        #[cfg(php_gc_status)]
        zend::zend_gc_get_status(status.as_mut_ptr());
        #[cfg(php_gc_status)]
        let status = status.assume_init();

        trace!(
            "Garbage collection with reason \"{reason}\" took {} nanoseconds",
            duration.as_nanos()
        );

        if let Some(profiler) = Profiler::get() {
            cfg_if::cfg_if! {
                if #[cfg(php_gc_status)] {
                    profiler.collect_garbage_collection(
                        // Safety: checked for `is_err()` above
                        now.unwrap().as_nanos() as i64,
                        duration.as_nanos() as i64,
                        reason,
                        collected as i64,
                        status.runs as i64,
                    );
                } else {
                    profiler.collect_garbage_collection(
                        // Safety: checked for `is_err()` above
                        now.unwrap().as_nanos() as i64,
                        duration.as_nanos() as i64,
                        reason,
                        collected as i64,
                    );
                }
            }
        }
        collected
    } else {
        // this should never happen, as it would mean that no `gc_collect_cycles` function pointer
        // did exist, which could only be the case if another extension was misbehaving.
        // But technically it could be, so better safe than sorry
        0
    }
}
