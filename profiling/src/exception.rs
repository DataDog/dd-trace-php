use crate::bindings as zend;
use crate::zend::ddog_php_prof_zend_string_view;
use crate::PROFILER;
use crate::REQUEST_LOCALS;
use log::{error, trace};
use std::cell::RefCell;

use rand_distr::{Distribution, Poisson};

/// The engine's previous throw exception hook
static mut PREV_ZEND_THROW_EXCEPTION_HOOK: Option<zend::VmZendThrowExceptionHook> = None;

/// take a sample every 100 exceptions
pub const EXCEPTION_PROFILING_INTERVAL: f64 = 100.0;

pub struct ExceptionProfilingStats {
    /// number of exceptions until next sample collection
    next_sample: i64,
}

impl ExceptionProfilingStats {
    fn new() -> ExceptionProfilingStats {
        ExceptionProfilingStats {
            next_sample: ExceptionProfilingStats::next_sampling_interval(),
        }
    }

    fn next_sampling_interval() -> i64 {
        Poisson::new(EXCEPTION_PROFILING_INTERVAL)
            .unwrap()
            .sample(&mut rand::thread_rng()) as i64
    }

    fn track_exception(&mut self, name: String) {
        self.next_sample -= 1;

        if self.next_sample > 0 {
            return;
        }

        self.next_sample = ExceptionProfilingStats::next_sampling_interval();

        REQUEST_LOCALS.with(|cell| {
            // try to borrow and bail out if not successful
            let locals = match cell.try_borrow() {
                Ok(locals) => locals,
                Err(_) => {
                    return;
                }
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
                unsafe {
                    profiler.collect_exception(
                        zend::ddog_php_prof_get_current_execute_data(),
                        name,
                        &locals,
                    )
                };
            }
        });
    }
}

thread_local! {
    static EXCEPTION_PROFILING_STATS: RefCell<ExceptionProfilingStats> = RefCell::new(ExceptionProfilingStats::new());
}

pub fn exception_profiling_rinit() {
    let exception_profiling: bool = REQUEST_LOCALS.with(|cell| {
        match cell.try_borrow() {
            Ok(locals) => locals.profiling_experimental_exception_enabled,
            Err(_err) => {
                error!("Exception profiling was not initialized correctly due to a borrow error. Please report this to Datadog.");
                false
            }
        }
    });

    if !exception_profiling {
        return;
    }

    trace!("Exception profiling enabled");
    unsafe {
        PREV_ZEND_THROW_EXCEPTION_HOOK = zend::zend_throw_exception_hook;
        zend::zend_throw_exception_hook = Some(exception_profiling_throw_exception_hook);
    }
}

pub fn exception_profiling_rshutdown() {}

unsafe extern "C" fn exception_profiling_throw_exception_hook(exception: *mut zend::zend_object) {
    let exception_name =
        ddog_php_prof_zend_string_view((*(*exception).ce).name.as_mut()).to_string();

    EXCEPTION_PROFILING_STATS.with(|cell| {
        let mut exceptions = cell.borrow_mut();
        exceptions.track_exception(exception_name)
    });
}
