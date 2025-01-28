#[cfg(feature = "allocation_profiling")]
use crate::allocation::alloc_prof_rshutdown;
use crate::{config, Profiler};
use log::trace;

pub(crate) fn startup() {
    unsafe {
        libc::pthread_atfork(Some(prepare), Some(parent), Some(child));
    }
}

extern "C" fn prepare() {
    // Hold mutexes across the handler. If there are any spurious wakeups by
    // the threads while the fork is occurring, they cannot acquire locks
    // since this thread holds them, preventing a deadlock situation.
    if let Some(profiler) = Profiler::get() {
        trace!("Preparing profiler for upcomming fork call.");
        let _ = profiler.fork_prepare();
    }
}

extern "C" fn parent() {
    if let Some(profiler) = Profiler::get() {
        trace!("Re-enabling profiler in parent after fork call.");
        profiler.post_fork_parent();
    }
}

unsafe extern "C" fn child() {
    if Profiler::get().is_none() {
        // No profiler, so nothing to do. This can happen in Apache forking SAPI, where Apache
        // would first go through MINIT phase and then fork(), so we'd observe the fork but there
        // is no profiler yet.
        return;
    }
    trace!("Shutting down profiler for child process after fork");
    // Disable the profiler because this is the child, and we don't support this yet.
    // And then leak the old profiler. Its drop method is not safe to run in these situations.
    Profiler::kill();

    #[cfg(feature = "allocation_profiling")]
    alloc_prof_rshutdown();

    // Reset some global state to prevent further profiling and to not handle
    // any pending interrupts.
    // SAFETY: done after config is used to shut down other things, and in a
    //         thread-safe context.
    config::on_fork_in_child();
}