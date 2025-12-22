use crate::bindings::ddog_php_prof_get_current_execute_data;
use crate::Profiler;
use libc::{c_int, c_void, sigaction, siginfo_t, SA_RESTART, SA_SIGINFO};
use std::mem;
use std::ptr;
use std::sync::atomic::{AtomicBool, Ordering};

static SIGNAL_HANDLER_ACTIVE: AtomicBool = AtomicBool::new(false);

extern "C" fn profiling_signal_handler(_signum: c_int, _info: *mut siginfo_t, _context: *mut c_void) {
    // Avoid re-entrancy if possible, though strict re-entrancy isn't guaranteed with this logic alone
    // in a signal handler. However, for profiling, we usually just skip if busy.
    // For now, let's just try to collect.

    // Safety: Accessing global state in a signal handler is risky.
    // Profiler::get() reads a static OnceCell.
    if let Some(profiler) = Profiler::get() {
        let execute_data = unsafe { ddog_php_prof_get_current_execute_data() };
        if !execute_data.is_null() {
            profiler.collect_time(execute_data, 1);
        }
    }
}

pub fn start_profiling_mechanism() {
    if SIGNAL_HANDLER_ACTIVE.swap(true, Ordering::SeqCst) {
        return; // Already started
    }

    #[cfg(target_os = "linux")]
    start_linux_timer();

    #[cfg(target_os = "macos")]
    start_macos_sidecar();
}

#[cfg(target_os = "linux")]
fn start_linux_timer() {
    use libc::{
        timer_create, timer_settime, sigevent, itimerspec, timespec,
        CLOCK_MONOTONIC, SIGEV_THREAD, SIGRTMIN, syscall, SYS_gettid
    };

    unsafe {
        // 1. Pick a signal: SIGRTMIN + 1
        // SIGRTMIN is a macro or function in libc usually.
        let signo = SIGRTMIN() + 1;

        // 2. Register Signal Handler
        let mut sa: sigaction = mem::zeroed();
        sa.sa_sigaction = profiling_signal_handler as usize;
        sa.sa_flags = SA_SIGINFO | SA_RESTART;
        libc::sigemptyset(&mut sa.sa_mask);
        libc::sigaction(signo, &sa, ptr::null_mut());

        // 3. Create Timer
        let mut sev: sigevent = mem::zeroed();
        sev.sigev_notify = SIGEV_THREAD;
        sev.sigev_signo = signo;
        sev.sigev_value.sival_ptr = ptr::null_mut();
        // Target the current thread (the PHP main thread)
        sev.sigev_notify_thread_id = syscall(SYS_gettid) as i32;

        let mut timer_id: libc::timer_t = mem::zeroed();
        if timer_create(CLOCK_MONOTONIC, &mut sev, &mut timer_id) == 0 {
            // 4. Arm Timer (10ms)
            let interval = timespec {
                tv_sec: 0,
                tv_nsec: 10 * 1_000_000, // 10ms
            };
            let its = itimerspec {
                it_interval: interval,
                it_value: interval,
            };
            timer_settime(timer_id, 0, &its, ptr::null_mut());

            // We might want to store timer_id somewhere to delete it later,
            // but for this example we leak it or let it die with the process.
        }
    }
}

#[cfg(target_os = "macos")]
fn start_macos_sidecar() {
    use libc::{SIGURG, pthread_self, pthread_t};
    use std::thread;
    use std::time::Duration;

    unsafe {
        // 1. Register Signal Handler
        let signo = SIGURG;
        let mut sa: sigaction = mem::zeroed();
        sa.sa_sigaction = profiling_signal_handler as usize;
        // SA_RESTART is important so we don't interrupt blocking syscalls like read() with EINTR too often
        // unless we want to? For profiling, we generally want to see what's happening.
        sa.sa_flags = SA_SIGINFO | SA_RESTART;
        libc::sigemptyset(&mut sa.sa_mask);
        libc::sigaction(signo, &sa, ptr::null_mut());

        // 2. Capture Main Thread ID
        let main_thread: pthread_t = pthread_self();

        // 3. Spawn Sidecar Thread
        thread::Builder::new()
            .name("ddprof_sidecar".to_string())
            .spawn(move || {
                loop {
                    thread::sleep(Duration::from_millis(10));
                    // Send signal to main thread
                    let ret = libc::pthread_kill(main_thread, signo);
                    if ret != 0 {
                        // Main thread might have exited?
                        break;
                    }
                }
            })
            .expect("Failed to spawn sidecar thread");
    }
}
