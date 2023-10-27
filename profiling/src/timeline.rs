use crate::bindings as zend;
use crate::zend::{zai_str_from_zstr, zend_get_executed_filename_ex, InternalFunctionHandler};
use crate::{PROFILER, REQUEST_LOCALS};
use libc::c_char;
use log::{error, trace};
use std::cell::RefCell;
use std::ffi::CStr;
use std::mem::MaybeUninit;
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

/// This functions needs to be called in MINIT of the module
pub fn timeline_minit() {
    unsafe {
        // register our function in the `gc_collect_cycles` pointer
        PREV_GC_COLLECT_CYCLES = zend::gc_collect_cycles;
        zend::gc_collect_cycles = Some(ddog_php_prof_gc_collect_cycles);

        // register our function in the `zend_compile_file` pointer
        PREV_ZEND_COMPILE_FILE = zend::zend_compile_file;
        zend::zend_compile_file = Some(ddog_php_prof_compile_file);

        // register our function in the `zend_compile_string` pointer
        PREV_ZEND_COMPILE_STRING = zend::zend_compile_string;
        zend::zend_compile_string = Some(ddog_php_prof_compile_string);
    }
}

// handlers for functions that sleep
static mut SLEEP_HANDLER: InternalFunctionHandler = None;
static mut USLEEP_HANDLER: InternalFunctionHandler = None;
static mut TIME_NANOSLEEP_HANDLER: InternalFunctionHandler = None;
static mut TIME_SLEEP_UNTIL_HANDLER: InternalFunctionHandler = None;

// handlers for stream blocking functions
static mut STREAM_SELECT_HANDLER: InternalFunctionHandler = None;
static mut SOCKET_SELECT_HANDLER: InternalFunctionHandler = None;
static mut SOCKET_ACCEPT_HANDLER: InternalFunctionHandler = None;
static mut CURL_MULTI_SELECT_HANDLER: InternalFunctionHandler = None;

// also stream blocking, but those functions are mostly used in event loops
static mut UV_RUN_HANDLER: InternalFunctionHandler = None;
static mut EVENT_BASE_LOOP_HANDLER: InternalFunctionHandler = None;
static mut EV_LOOP_RUN_HANDLER: InternalFunctionHandler = None;
static mut EVENTBASE_LOOP_HANDLER: InternalFunctionHandler = None;

fn report_wait_time(
    handler: InternalFunctionHandler,
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
    reason: &'static str,
) {
    let start = Instant::now();

    if let Some(func) = handler {
        unsafe { func(execute_data, return_value) };
    }

    let duration = start.elapsed();
    let now = SystemTime::now().duration_since(UNIX_EPOCH);

    REQUEST_LOCALS.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(locals) = cell.try_borrow() else {
            return;
        };

        if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
            profiler.collect_idle(
                // Safety: checked for `is_err()` above
                now.unwrap().as_nanos() as i64,
                duration.as_nanos() as i64,
                reason,
                &locals,
            );
        }
    });
}

/// Wrapping the PHP `sleep()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_sleep(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(SLEEP_HANDLER, execute_data, return_value, "sleeping");
}

/// Wrapping the PHP `usleep()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_usleep(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(USLEEP_HANDLER, execute_data, return_value, "sleeping");
}

/// Wrapping the PHP `time_nanosleep()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_time_nanosleep(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(
        TIME_NANOSLEEP_HANDLER,
        execute_data,
        return_value,
        "sleeping",
    );
}

/// Wrapping the PHP `time_sleep_until()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_time_sleep_until(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(
        TIME_SLEEP_UNTIL_HANDLER,
        execute_data,
        return_value,
        "sleeping",
    );
}

/// Wrapping the PHP `stream_select()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_stream_select(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(STREAM_SELECT_HANDLER, execute_data, return_value, "select");
}

/// Wrapping the PHP `socket_select()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_socket_select(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(SOCKET_SELECT_HANDLER, execute_data, return_value, "select");
}

/// Wrapping the PHP `socket_accept()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_socket_accept(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(SOCKET_ACCEPT_HANDLER, execute_data, return_value, "select");
}

/// Wrapping the PHP `curl_multi_select()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_curl_multi_accept(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(
        CURL_MULTI_SELECT_HANDLER,
        execute_data,
        return_value,
        "select",
    );
}

/// Wrapping the PHP `uv_run()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_uv_run(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(UV_RUN_HANDLER, execute_data, return_value, "select");
}

/// Wrapping the PHP `event_base_loop()` function to take the time it is blocking the current thread
unsafe extern "C" fn php_event_base_loop(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(
        EVENT_BASE_LOOP_HANDLER,
        execute_data,
        return_value,
        "select",
    );
}

/// Wrapping the PHP `\EventBase\loop()` method to take the time it is blocking the current thread
unsafe extern "C" fn php_eventbase_loop(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(EVENTBASE_LOOP_HANDLER, execute_data, return_value, "select");
}

/// Wrapping the PHP `\EvLoop\run()` method to take the time it is blocking the current thread
unsafe extern "C" fn php_ev_loop_run(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    report_wait_time(EV_LOOP_RUN_HANDLER, execute_data, return_value, "select");
}

/// This function is run during the STARTUP phase and hooks into the execution of some functions
/// that we'd like to observe in regards of visualization on the timeline
pub unsafe fn timeline_startup() {
    let handlers = [
        // functions that put the thread into sleep
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"sleep\0"),
            &mut SLEEP_HANDLER,
            Some(php_sleep),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"usleep\0"),
            &mut USLEEP_HANDLER,
            Some(php_usleep),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"time_nanosleep\0"),
            &mut TIME_NANOSLEEP_HANDLER,
            Some(php_time_nanosleep),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"time_sleep_until\0"),
            &mut TIME_SLEEP_UNTIL_HANDLER,
            Some(php_time_sleep_until),
        ),
        // functions that block on a stream to become read-/writeable
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"stream_select\0"),
            &mut STREAM_SELECT_HANDLER,
            Some(php_stream_select),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"socket_select\0"),
            &mut SOCKET_SELECT_HANDLER,
            Some(php_socket_select),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"socket_accept\0"),
            &mut SOCKET_ACCEPT_HANDLER,
            Some(php_socket_accept),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"curl_multi_select\0"),
            &mut CURL_MULTI_SELECT_HANDLER,
            Some(php_curl_multi_accept),
        ),
        // provided by `ext-uv` from https://pecl.php.net/package/uv
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"uv_run\0"),
            &mut UV_RUN_HANDLER,
            Some(php_uv_run),
        ),
        // provided by `ext-libevent` from https://pecl.php.net/package/libevent
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"event_base_loop\0"),
            &mut EVENT_BASE_LOOP_HANDLER,
            Some(php_event_base_loop),
        ),
    ];

    for handler in handlers.into_iter() {
        // Safety: we've set all the parameters correctly for this C call.
        zend::datadog_php_install_handler(handler);
    }

    let handlers = [
        // provided by `ext-ev` from https://pecl.php.net/package/ev
        zend::datadog_php_zim_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"evloop\0"),
            CStr::from_bytes_with_nul_unchecked(b"run\0"),
            &mut EV_LOOP_RUN_HANDLER,
            Some(php_ev_loop_run),
        ),
        // provided by `ext-event` from https://pecl.php.net/package/event
        zend::datadog_php_zim_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"eventbase\0"),
            CStr::from_bytes_with_nul_unchecked(b"loop\0"),
            &mut EVENTBASE_LOOP_HANDLER,
            Some(php_eventbase_loop),
        ),
    ];

    for handler in handlers.into_iter() {
        // Safety: we've set all the parameters correctly for this C call.
        zend::datadog_php_install_method_handler(handler);
    }
}

thread_local! {
    static IDLE_SINCE: RefCell<Instant> = RefCell::new(Instant::now());
}

/// This function is run during the RINIT phase and reports any `IDLE_SINCE` duration as an idle
/// period for this PHP thread
pub fn timeline_rinit() {
    REQUEST_LOCALS.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(locals) = cell.try_borrow() else {
            return;
        };

        IDLE_SINCE.with(|cell| {
            // try to borrow and bail out if not successful
            let Ok(idle_since) = cell.try_borrow() else {
                return;
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_idle(
                    // Safety: checked for `is_err()` above
                    SystemTime::now()
                        .duration_since(UNIX_EPOCH)
                        .unwrap()
                        .as_nanos() as i64,
                    idle_since.elapsed().as_nanos() as i64,
                    "idle",
                    &locals,
                );
            }
        });
    });
}

/// This function is run during the P-RSHUTDOWN phase and resets the `IDLE_SINCE` thread local to
/// "now", indicating the start of a new idle phase
pub fn timeline_prshutdown() {
    IDLE_SINCE.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(mut idle_since) = cell.try_borrow_mut() else {
            return;
        };
        *idle_since = Instant::now();
    })
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
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.profiling_experimental_timeline_enabled)
                .unwrap_or(false)
        });

        if !timeline_enabled {
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
        REQUEST_LOCALS.with(|cell| {
            // try to borrow and bail out if not successful
            let Ok(locals) = cell.try_borrow() else {
                return;
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_compile_string(
                    // Safety: checked for `is_err()` above
                    now.unwrap().as_nanos() as i64,
                    duration.as_nanos() as i64,
                    filename,
                    line,
                    &locals,
                );
            }
        });
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
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.profiling_experimental_timeline_enabled)
                .unwrap_or(false)
        });

        if !timeline_enabled {
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
        REQUEST_LOCALS.with(|cell| {
            // try to borrow and bail out if not successful
            let Ok(locals) = cell.try_borrow() else {
                return;
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_compile_file(
                    // Safety: checked for `is_err()` above
                    now.unwrap().as_nanos() as i64,
                    duration.as_nanos() as i64,
                    filename,
                    include_type,
                    &locals,
                );
            }
        });
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
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.profiling_experimental_timeline_enabled)
                .unwrap_or(false)
        });

        if !timeline_enabled {
            return prev();
        }

        #[cfg(php_gc_status)]
        let mut status = MaybeUninit::<zend::zend_gc_status>::uninit();

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

        REQUEST_LOCALS.with(|cell| {
            // try to borrow and bail out if not successful
            let Ok(locals) = cell.try_borrow() else {
                return;
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                cfg_if::cfg_if! {
                    if #[cfg(php_gc_status)] {
                        profiler.collect_garbage_collection(
                            // Safety: checked for `is_err()` above
                            now.unwrap().as_nanos() as i64,
                            duration.as_nanos() as i64,
                            reason,
                            collected as i64,
                            status.runs as i64,
                            &locals,
                        );
                    } else {
                        profiler.collect_garbage_collection(
                            // Safety: checked for `is_err()` above
                            now.unwrap().as_nanos() as i64,
                            duration.as_nanos() as i64,
                            reason,
                            collected as i64,
                            &locals,
                        );
                    }
                }
            }
        });
        collected
    } else {
        // this should never happen, as it would mean that no `gc_collect_cycles` function pointer
        // did exist, which could only be the case if another extension was misbehaving.
        // But technically it could be, so better safe than sorry
        0
    }
}
