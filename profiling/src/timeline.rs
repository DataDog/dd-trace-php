use crate::zend::{
    self, zai_str_from_zstr, zend_execute_data, zend_get_executed_filename_ex, zval,
    InternalFunctionHandler,
};
use crate::{PROFILER, REQUEST_LOCALS};
use libc::c_char;
use log::{error, trace, warn};
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

static mut SLEEP_HANDLER: InternalFunctionHandler = None;
static mut USLEEP_HANDLER: InternalFunctionHandler = None;
static mut TIME_NANOSLEEP_HANDLER: InternalFunctionHandler = None;
static mut TIME_SLEEP_UNTIL_HANDLER: InternalFunctionHandler = None;

thread_local! {
    static IDLE_SINCE: RefCell<Instant> = RefCell::new(Instant::now());
}

#[inline]
fn try_sleeping_fn(
    func: unsafe extern "C" fn(execute_data: *mut zend_execute_data, return_value: *mut zval),
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) -> anyhow::Result<()> {
    let timeline_enabled = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_timeline_enabled)
            .unwrap_or(false)
    });

    if !timeline_enabled {
        unsafe { func(execute_data, return_value) };
        return Ok(());
    }

    let start = Instant::now();

    // Consciously not holding request locals/profiler during the forwarded
    // call. If they are, then it's possible to get a deadlock/bad borrow
    // because the call triggers something to happen like a time/allocation
    // sample and the extension tries to re-acquire these.
    // SAFETY: simple forwarding to original func with original args.
    unsafe { func(execute_data, return_value) };

    let duration = start.elapsed();

    // > Returns an Err if earlier is later than self, and the error contains
    // > how far from self the time is.
    // This shouldn't ever happen (now is always later than the epoch) but in
    // case it does, short-circuit the function.
    let now = SystemTime::now().duration_since(UNIX_EPOCH)?;

    match PROFILER.lock() {
        Ok(guard) => {
            // If the profiler isn't there, it's probably just not enabled.
            if let Some(profiler) = guard.as_ref() {
                let now = now.as_nanos() as i64;
                let duration = duration.as_nanos() as i64;
                profiler.collect_idle(now, duration, "sleeping")
            }
        }
        Err(err) => anyhow::bail!("profiler mutex: {err:#}"),
    }
    Ok(())
}

fn sleeping_fn(
    func: unsafe extern "C" fn(execute_data: *mut zend_execute_data, return_value: *mut zval),
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    if let Err(err) = try_sleeping_fn(func, execute_data, return_value) {
        warn!("error creating profiling timeline sample for an internal function: {err:#}");
    }
}

/// Wrapping the PHP `sleep()` function to take the time it is blocking the current thread
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_sleep(
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    if let Some(func) = SLEEP_HANDLER {
        sleeping_fn(func, execute_data, return_value)
    }
}

/// Wrapping the PHP `usleep()` function to take the time it is blocking the current thread
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_usleep(
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    if let Some(func) = USLEEP_HANDLER {
        sleeping_fn(func, execute_data, return_value)
    }
}

/// Wrapping the PHP `time_nanosleep()` function to take the time it is blocking the current thread
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_time_nanosleep(
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    if let Some(func) = TIME_NANOSLEEP_HANDLER {
        sleeping_fn(func, execute_data, return_value)
    }
}

/// Wrapping the PHP `time_sleep_until()` function to take the time it is blocking the current thread
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_time_sleep_until(
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    if let Some(func) = TIME_SLEEP_UNTIL_HANDLER {
        sleeping_fn(func, execute_data, return_value)
    }
}

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

/// This function is run during the STARTUP phase and hooks into the execution of some functions
/// that we'd like to observe in regards of visualization on the timeline
pub unsafe fn timeline_startup() {
    let handlers = [
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"sleep\0"),
            &mut SLEEP_HANDLER,
            Some(ddog_php_prof_sleep),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"usleep\0"),
            &mut USLEEP_HANDLER,
            Some(ddog_php_prof_usleep),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"time_nanosleep\0"),
            &mut TIME_NANOSLEEP_HANDLER,
            Some(ddog_php_prof_time_nanosleep),
        ),
        zend::datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"time_sleep_until\0"),
            &mut TIME_SLEEP_UNTIL_HANDLER,
            Some(ddog_php_prof_time_sleep_until),
        ),
    ];

    for handler in handlers.into_iter() {
        // Safety: we've set all the parameters correctly for this C call.
        zend::datadog_php_install_handler(handler);
    }
}

/// This function is run during the RINIT phase and reports any `IDLE_SINCE` duration as an idle
/// period for this PHP thread.
/// # SAFETY
/// Must be called only in rinit and after [crate::config::first_rinit].
pub unsafe fn timeline_rinit() {
    IDLE_SINCE.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(idle_since) = cell.try_borrow() else {
            return;
        };

        REQUEST_LOCALS.with(|cell| {
            let is_timeline_enabled = cell
                .try_borrow()
                .map(|locals| locals.system_settings().profiling_timeline_enabled)
                .unwrap_or(false);
            if !is_timeline_enabled {
                return;
            }

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_idle(
                    // Safety: checked for `is_err()` above
                    SystemTime::now()
                        .duration_since(UNIX_EPOCH)
                        .unwrap()
                        .as_nanos() as i64,
                    idle_since.elapsed().as_nanos() as i64,
                    "idle",
                );
            }
        });
    });
}

/// This function is run during the P-RSHUTDOWN phase and resets the `IDLE_SINCE` thread local to
/// "now", indicating the start of a new idle phase
pub fn timeline_prshutdown() {
    let timeline_enabled = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_timeline_enabled)
            .unwrap_or(false)
    });

    if !timeline_enabled {
        return;
    }

    IDLE_SINCE.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(mut idle_since) = cell.try_borrow_mut() else {
            return;
        };
        *idle_since = Instant::now();
    })
}

/// This function is run during the MSHUTDOWN phase and reports any `IDLE_SINCE` duration as an idle
/// period for this PHP thread. This will report the last `IDLE_SINCE` duration created in the last
/// `P-RSHUTDOWN` (just above) when the PHP process is shutting down.
/// # Saftey
/// Must be called in shutdown before [crate::config::shutdown].
pub(crate) unsafe fn timeline_mshutdown() {
    IDLE_SINCE.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(idle_since) = cell.try_borrow() else {
            return;
        };

        REQUEST_LOCALS.with(|cell| {
            let is_timeline_enabled = cell
                .try_borrow()
                .map(|locals| locals.system_settings().profiling_timeline_enabled)
                .unwrap_or(false);

            if !is_timeline_enabled {
                return;
            }

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_idle(
                    // Safety: checked for `is_err()` above
                    SystemTime::now()
                        .duration_since(UNIX_EPOCH)
                        .unwrap()
                        .as_nanos() as i64,
                    idle_since.elapsed().as_nanos() as i64,
                    "idle",
                );
            }
        });
    });
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
                .map(|locals| locals.system_settings().profiling_timeline_enabled)
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

        if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
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
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.system_settings().profiling_timeline_enabled)
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

        if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
            profiler.collect_compile_file(
                // Safety: checked for `is_err()` above
                now.unwrap().as_nanos() as i64,
                duration.as_nanos() as i64,
                filename,
                include_type,
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
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.system_settings().profiling_timeline_enabled)
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
