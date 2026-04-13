use crate::allocation::alloc_prof_rshutdown;
use crate::{config, Profiler};
use log::trace;
use std::cell::Cell;

// Tracks whether fork_prepare() successfully entered the barrier for the
// current fork. post_fork_parent() must only call fork_barrier.wait() when
// fork_prepare() did; otherwise the barrier never reaches its required count
// and the parent deadlocks indefinitely.
//
// A thread-local is correct here because prepare() and parent() are always
// invoked on the same thread (the one that called fork()).  The flag is
// cleared by parent() after reading it so that nested or subsequent forks
// start fresh.
thread_local! {
    static FORK_PREPARE_BARRIER_ENTERED: Cell<bool> = const { Cell::new(false) };
}

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
        trace!("Preparing profiler for upcoming fork call.");
        let entered = profiler.fork_prepare().is_ok();
        FORK_PREPARE_BARRIER_ENTERED.with(|c| c.set(entered));
    }
}

extern "C" fn parent() {
    // Only enter the barrier if fork_prepare() entered it.  If the background
    // threads were not alive when fork() was called, fork_prepare() returns
    // Err without touching the barrier, so post_fork_parent() must also skip
    // it — otherwise we wait for two parties that will never arrive.
    if FORK_PREPARE_BARRIER_ENTERED.with(|c| c.replace(false)) {
        if let Some(profiler) = Profiler::get() {
            trace!("Re-enabling profiler in parent after fork call.");
            profiler.post_fork_parent();
        }
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

    alloc_prof_rshutdown();

    // Reset some global state to prevent further profiling and to not handle
    // any pending interrupts.
    // SAFETY: done after config is used to shut down other things, and in a
    //         thread-safe context.
    config::on_fork_in_child();
}
