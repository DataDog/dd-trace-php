use log::error;
use log::trace;

use crate::bindings as zend;
use crate::zend::ddog_php_prof_zend_string_view;
use crate::PROFILER;
use crate::REQUEST_LOCALS;
use std::mem::MaybeUninit;
use std::ptr;
use std::time::Instant;
use std::time::SystemTime;
use std::time::UNIX_EPOCH;

/// The engine's original (or neighbouring extensions) `gc_collect_cycles()` function
static mut PREV_GC_COLLECT_CYCLES: Option<zend::VmGcCollectCyclesFn> = None;

/// The engine's original (or neighbouring extensions) `zend_compile_file()` function
static mut PREV_ZEND_COMPILE_FILE: Option<zend::VmZendCompileFile> = None;

pub fn timeline_minit() {
    unsafe {
        // register our function in the `gc_collect_cycles` pointer
        PREV_GC_COLLECT_CYCLES = zend::gc_collect_cycles;
        zend::gc_collect_cycles = Some(ddog_php_prof_gc_collect_cycles);
        // register our function in the `zend_compile_file` pointer
        PREV_ZEND_COMPILE_FILE = zend::zend_compile_file;
        zend::zend_compile_file = Some(ddog_php_prof_compile_file);
    }
}

unsafe extern "C" fn ddog_php_prof_compile_file(
    handle: *mut zend::zend_file_handle,
    r#type: i32,
) -> *mut zend::_zend_op_array {
    if let Some(prev) = PREV_ZEND_COMPILE_FILE {
        let start = Instant::now();
        let op_array = prev(handle, r#type);
        let duration = start.elapsed();
        let now = SystemTime::now().duration_since(UNIX_EPOCH);

        // include/require failed, could be invalid PHP or file not found, ...
        // or time went backwards
        // TODO we might collect this event anyway and label it accordingly in a later stage of
        // this feature
        if op_array.is_null() || (*op_array).filename.is_null() || now.is_err() {
            return op_array;
        }

        // Safety: check for `is_err()` in the if above

        REQUEST_LOCALS.with(|cell| {
            // Panic: there might already be a mutable reference to `REQUEST_LOCALS`
            let locals = cell.try_borrow();
            if locals.is_err() {
                return;
            }
            // Safety: got checked above
            let locals = locals.unwrap();

            if !locals.profiling_experimental_timeline_enabled {
                return;
            }

            let include_type = match r#type as u32 {
                zend::ZEND_INCLUDE => "include", // `include_once()` and `include_once()`
                zend::ZEND_REQUIRE => "require", // `require()` and `require_once()`
                _default => "",
            };

            // extract the filename from the returned op_array
            // we could also extract from the handle, but those filenames might be different from
            // the one in the `op_array`: In the handle we get what `include()` was called with,
            // for example "/var/www/html/../vendor/foo/bar.php" while during stack walking we get
            // "/var/html/vendor/foo/bar.php". This makes sure it is the exact same string we'd
            // collect in stack walking and therefore we are fully utilizing the pprof string table
            let filename = Some(String::from_utf8_lossy(
                ddog_php_prof_zend_string_view((*op_array).filename.as_mut()).into_bytes(),
            )).unwrap();

            trace!(
                "Compile file \"{filename}\" with include type \"{include_type}\" took {} nanoseconds",
                duration.as_nanos(),
            );

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_compile_file(
                    // Safety: checked for `is_err()` above
                    now.unwrap().as_nanos() as i64,
                    duration.as_nanos() as i64,
                    filename.to_string(),
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
            // Panic: there might already be a mutable reference to `REQUEST_LOCALS`
            let locals = cell.try_borrow();
            if locals.is_err() {
                return;
            }
            let locals = locals.unwrap();

            if !locals.profiling_experimental_timeline_enabled {
                return;
            }

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
