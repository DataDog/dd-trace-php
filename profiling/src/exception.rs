use crate::bindings as zend;
use crate::PROFILER;
use crate::REQUEST_LOCALS;
use log::{error, trace};
use std::cell::RefCell;
use std::sync::atomic::AtomicI64;
use std::sync::atomic::Ordering;

use rand_distr::{Distribution, Poisson};

/// The engine's previous throw exception hook
static mut PREV_ZEND_THROW_EXCEPTION_HOOK: Option<zend::VmZendThrowExceptionHook> = None;

/// take a sample every 100 exceptions
pub static EXCEPTION_PROFILING_INTERVAL: AtomicI64 = AtomicI64::new(100);

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
        Poisson::new(EXCEPTION_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64)
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
            let Ok(locals) = cell.try_borrow() else {
                return;
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

pub fn exception_profiling_minit() {
    unsafe {
        PREV_ZEND_THROW_EXCEPTION_HOOK = zend::zend_throw_exception_hook;
        zend::zend_throw_exception_hook = Some(exception_profiling_throw_exception_hook);
    }
}

pub fn exception_profiling_rinit() {
    let (exception_profiling, sampling_distance) = REQUEST_LOCALS.with(|cell| {
        match cell.try_borrow() {
            Ok(locals) => (locals.profiling_experimental_exception_enabled, locals.profiling_experimental_exception_sampling_distance),
            Err(_err) => {
                error!("Exception profiling was not initialized correctly due to a borrow error. Please report this to Datadog.");
                (false, 0)
            }
        }
    });

    if !exception_profiling {
        return;
    }

    EXCEPTION_PROFILING_INTERVAL.store(sampling_distance, Ordering::Relaxed);

    trace!("Exception profiling enabled with sampling disance of {sampling_distance}");
}

pub fn exception_profiling_mshutdown() {
    unsafe {
        zend::zend_throw_exception_hook = PREV_ZEND_THROW_EXCEPTION_HOOK;
        PREV_ZEND_THROW_EXCEPTION_HOOK = None;
    }
}

unsafe extern "C" fn exception_profiling_throw_exception_hook(
    #[cfg(php7)] exception: *mut zend::zval,
    #[cfg(php8)] exception: *mut zend::zend_object,
) {
    let exception_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.profiling_experimental_exception_enabled)
            .unwrap_or(false)
    });

    if exception_profiling {
        #[cfg(php7)]
        let exception_name = (*(*exception).value.obj).class_name();
        #[cfg(php8)]
        let exception_name = (*exception).class_name();

        EXCEPTION_PROFILING_STATS.with(|cell| {
            let mut exceptions = cell.borrow_mut();
            exceptions.track_exception(exception_name)
        });
    }

    if let Some(prev) = PREV_ZEND_THROW_EXCEPTION_HOOK {
        prev(exception);
    }
}
