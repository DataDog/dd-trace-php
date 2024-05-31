use crate::zend::{self, zend_execute_data, zend_generator, zval, InternalFunctionHandler};
use crate::PROFILER;
use crate::REQUEST_LOCALS;
use log::{error, info};
use rand::rngs::ThreadRng;
use std::cell::RefCell;
use std::ffi::CStr;
use std::ptr;
use std::sync::atomic::AtomicU32;
use std::sync::atomic::Ordering;

use rand_distr::{Distribution, Poisson};

/// The engine's previous throw exception hook.
/// We need to occupy the `zend_throw_exception_hook` in MINIT which is before threads get started
/// (in ZTS), so we do not need a thread local for this function pointer.
static mut PREV_ZEND_THROW_EXCEPTION_HOOK: Option<zend::VmZendThrowExceptionHook> = None;

/// Take a sample every 100 exceptions
/// Will be initialized on first RINIT and is controlled by a INI_SYSTEM, so we do not need a
/// thread local for the profiling interval.
pub static EXCEPTION_PROFILING_INTERVAL: AtomicU32 = AtomicU32::new(100);

/// This will store the number of exceptions thrown during a profiling period. It will overflow
/// when throwing more then 4_294_967_295 exceptions during this period which we currently
/// believe will bring down your application anyway, so accurate numbers are not a problem.
pub static EXCEPTION_PROFILING_EXCEPTION_COUNT: AtomicU32 = AtomicU32::new(0);

pub struct ExceptionProfilingStats {
    /// number of exceptions until next sample collection
    next_sample: u32,
    poisson: Poisson<f64>,
    rng: ThreadRng,
}

impl ExceptionProfilingStats {
    fn new() -> ExceptionProfilingStats {
        // Safety: this will only error if lambda <= 0
        let poisson =
            Poisson::new(EXCEPTION_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64).unwrap();
        let mut stats = ExceptionProfilingStats {
            next_sample: 0,
            poisson,
            rng: rand::thread_rng(),
        };
        stats.next_sampling_interval();
        stats
    }

    fn next_sampling_interval(&mut self) {
        self.next_sample = self.poisson.sample(&mut self.rng) as u32;
    }

    fn track_exception(
        &mut self,
        #[cfg(php7)] exception: *mut zend::zval,
        #[cfg(php8)] exception: *mut zend::zend_object,
    ) {
        if let Some(next_sample) = self.next_sample.checked_sub(1) {
            self.next_sample = next_sample;
            return;
        }

        #[cfg(php7)]
        let exception = unsafe { (*exception).value.obj };

        let exception_name = unsafe { (*exception).class_name() };

        let collect_message = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.system_settings().profiling_exception_message_enabled)
                .unwrap_or(false)
        });

        let message = if collect_message {
            Some(unsafe {
                zend::zai_str_from_zstr(zend::zai_exception_message(exception).as_mut())
                    .into_string()
            })
        } else {
            None
        };

        self.next_sampling_interval();

        if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
            // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
            unsafe {
                profiler.collect_exception(
                    zend::ddog_php_prof_get_current_execute_data(),
                    exception_name,
                    message,
                )
            };
        }
    }
}

thread_local! {
    static EXCEPTION_PROFILING_STATS: RefCell<ExceptionProfilingStats> = RefCell::new(ExceptionProfilingStats::new());
}

static mut GENERATOR_THROW_HANDLER: InternalFunctionHandler = None;

/// Wrapping the PHP `Generator::throw()` method to fixup the prev_execute_data of the fake frame
///
/// If an exception gets thrown into a generator the `prev_execute_data` of the generator is a
/// left-over from the last generator call, which is a stack frame that has already been freed.
/// This fix sets the `prev_execute_data` to the current `execute_data` that got passed in, which
/// is the `Generator::throw()` frame itself.
///
/// See `tests/phpt/exceptions_generator_throw.phpt` for a reproducer and the upstream bug report
/// at https://github.com/php/php-src/issues/14387
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_generator_throw(
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    if let Some(func) = GENERATOR_THROW_HANDLER {
        // SAFETY: if Zend is not broken, the pointers are all valid
        let generator = (*execute_data).This.value.obj as *mut zend_generator;
        let generator_ex = (*generator).execute_data;
        // guard against Generator being already finished and execute_data freed
        if !generator_ex.is_null() {
            (*generator_ex).prev_execute_data = execute_data;
        }

        // SAFETY: simple forwarding to original func with original args.
        func(execute_data, return_value);
    }
}

pub fn exception_profiling_minit() {
    unsafe {
        PREV_ZEND_THROW_EXCEPTION_HOOK = zend::zend_throw_exception_hook;
        zend::zend_throw_exception_hook = Some(exception_profiling_throw_exception_hook);

        let method_handlers = [zend::datadog_php_zim_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"generator\0"),
            CStr::from_bytes_with_nul_unchecked(b"throw\0"),
            ptr::addr_of_mut!(GENERATOR_THROW_HANDLER),
            Some(ddog_php_prof_generator_throw),
        )];

        for handler in method_handlers.into_iter() {
            // Safety: we've set all the parameters correctly for this C call.
            zend::datadog_php_install_method_handler(handler);
        }
    }
}

/// This initializes the `EXCEPTION_PROFILING_INTERVAL` atomic on first RINIT with the value from
/// the INI / ENV variable.
pub fn exception_profiling_first_rinit() {
    let exception_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_exception_enabled)
            .unwrap_or(false)
    });

    if !exception_profiling {
        return;
    }

    let sampling_distance = REQUEST_LOCALS.with(|cell| {
        match cell.try_borrow() {
            Ok(locals) => locals.system_settings().profiling_exception_sampling_distance,
            Err(_err) => {
                error!("Exception profiling was not initialized correctly due to a borrow error. Please report this to Datadog.");
                100
            }
        }
    });

    EXCEPTION_PROFILING_INTERVAL.store(sampling_distance, Ordering::SeqCst);

    info!("Exception profiling initialized with sampling distance: {sampling_distance}");
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
    EXCEPTION_PROFILING_EXCEPTION_COUNT.fetch_add(1, Ordering::SeqCst);

    let exception_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_exception_enabled)
            .unwrap_or(false)
    });

    // Up to PHP 7.1, when PHP propagated exceptions up the call stack, it would re-throw them.
    // This process involved calling this hook for each stack frame or try...catch block it
    // traversed. Fortunately, this behavior can be easily identified by checking for a NULL
    // pointer.
    if exception_profiling && !exception.is_null() {
        EXCEPTION_PROFILING_STATS.with(|cell| {
            let mut exceptions = cell.borrow_mut();
            exceptions.track_exception(exception)
        });
    }

    if let Some(prev) = PREV_ZEND_THROW_EXCEPTION_HOOK {
        prev(exception);
    }
}
