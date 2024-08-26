use super::{InterruptManager, Profiler, SystemSettings};
use once_cell::sync::OnceCell;
use std::mem::forget;
use std::sync::Arc;
use std::time::Duration;

/// The global profiler. Profiler gets made during the first rinit after an
/// minit, and is destroyed on mshutdown.
static mut PROFILER: SystemProfiler = SystemProfiler::new();

pub struct SystemProfiler(OnceCell<Profiler>);

impl SystemProfiler {
    pub const fn new() -> Self {
        SystemProfiler(OnceCell::new())
    }

    /// Initializes the system profiler safely by one thread.
    pub fn init(system_settings: &mut SystemSettings) {
        // SAFETY: the `get_or_init` access is a thread-safe API, and the
        // PROFILER is not being mutated outside single-threaded phases such
        // as minit and mshutdown.
        unsafe { PROFILER.0.get_or_init(|| Profiler::new(system_settings)) };
    }

    pub fn get() -> Option<&'static Profiler> {
        // SAFETY: the `get` access is a thread-safe API, and the PROFILER is
        // not being mutated outside single-threaded phases such as minit and
        // mshutdown.
        unsafe { PROFILER.0.get() }
    }

    /// Begins the shutdown process. To complete it, call [Profiler::shutdown].
    /// Note that you must call [Profiler::shutdown] afterward; it's two
    /// parts of the same operation. It's split so you (or other extensions)
    /// can do something while the other threads finish up.
    pub fn stop(timeout: Duration) {
        // SAFETY: the `get_mut` access is a thread-safe API, and the PROFILER
        // is not being mutated outside single-threaded phases such as minit
        // and mshutdown.
        if let Some(profiler) = unsafe { PROFILER.0.get_mut() } {
            profiler.join_and_drop_sender(timeout);
        }
    }

    /// Completes the shutdown process; to start it, call [Profiler::stop]
    /// before calling [Profiler::shutdown].
    /// Note the timeout is per thread, and there may be multiple threads.
    ///
    /// Safety: only safe to be called in `SHUTDOWN`/`MSHUTDOWN` phase
    pub fn shutdown(timeout: Duration) {
        // SAFETY: the `take` access is a thread-safe API, and the PROFILER is
        // not being mutated outside single-threaded phases such as minit and
        // mshutdown.
        if let Some(profiler) = unsafe { PROFILER.0.take() } {
            profiler.join_collector_and_uploader(timeout);
        }
    }

    /// Throws away the profiler and moves it to uninitialized.
    ///
    /// In a forking situation, the currently active profiler may not be valid
    /// because it has join handles and other state shared by other threads,
    /// and threads are not copied when the process is forked.
    /// Additionally, if we've hit certain other issues like not being able to
    /// determine the return type of the pcntl_fork function, we don't know if
    /// we're the parent or child.
    /// So, we throw away the current profiler and forget it, which avoids
    /// running the destructor. Yes, this will leak some memory.
    ///
    /// # Safety
    /// Must be called when no other thread is using the PROFILER object. That
    /// includes this thread in some kind of recursive manner.
    pub unsafe fn kill() {
        // SAFETY: see this function's safety conditions.
        if let Some(mut profiler) = PROFILER.0.take() {
            // Drop some things to reduce memory.
            profiler.interrupt_manager = Arc::new(InterruptManager::new());
            profiler.message_sender = crossbeam_channel::bounded(0).0;
            profiler.upload_sender = crossbeam_channel::bounded(0).0;

            // But we're not 100% sure everything is safe to drop, notably the
            // join handles, so we leak the rest.
            forget(profiler)
        }
    }
}
