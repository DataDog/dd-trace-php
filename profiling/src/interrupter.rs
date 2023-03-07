use std::ptr::NonNull;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};

pub use platform::Interrupter;

// TODO: support forking

#[derive(Debug, Eq, PartialEq)]
pub struct SignalPointers {
    pub vm_interrupt: NonNull<AtomicBool>,
    pub cpu_samples: NonNull<AtomicU32>,
    pub wall_samples: NonNull<AtomicU32>,
}

#[cfg(target_os = "linux")]
mod platform {
    use super::*;
    use crate::bindings::{
        ddog_php_prof_timer_create, ddog_php_prof_timer_delete, ddog_php_prof_timer_settime,
    };
    use std::ffi::CStr;

    pub struct Interrupter {
        // todo: should be Pin but I couldn't figure out the API.
        // This is used, Rust just doesn't see it. It's holding onto the data
        // used by the notify handlers.
        #[allow(unused)]
        signal_pointers: Box<SignalPointers>,
        cpu_time_period_nanoseconds: u64,
        wall_time_period_nanoseconds: u64,
        cpu_timer: Timer,
        wall_timer: Timer,
    }

    impl Interrupter {
        pub fn new(
            signal_pointers: SignalPointers,
            cpu_time_period_nanoseconds: u64,
            wall_time_period_nanoseconds: u64,
        ) -> Self {
            let mut signal_pointers = Box::new(signal_pointers);
            let sival_ptr = signal_pointers.as_mut() as *mut SignalPointers as *mut libc::c_void;
            let cpu_sigval = libc::sigval { sival_ptr };
            let wall_sigval = libc::sigval { sival_ptr };
            Self {
                signal_pointers,
                cpu_time_period_nanoseconds,
                wall_time_period_nanoseconds,
                cpu_timer: Timer::new(libc::CLOCK_THREAD_CPUTIME_ID, cpu_sigval, Self::notify_cpu),
                wall_timer: Timer::new(libc::CLOCK_MONOTONIC, wall_sigval, Self::notify_wall),
            }
        }

        extern "C" fn notify_cpu(sigval: libc::sigval) {
            let signal_pointers = unsafe { &*(sigval.sival_ptr as *const SignalPointers) };
            let cpu_samples: &AtomicU32 = unsafe { signal_pointers.cpu_samples.as_ref() };
            cpu_samples.fetch_add(1, Ordering::SeqCst);

            let vm_interrupt: &AtomicBool = unsafe { signal_pointers.vm_interrupt.as_ref() };
            vm_interrupt.store(true, Ordering::SeqCst);
        }

        extern "C" fn notify_wall(sigval: libc::sigval) {
            let signal_pointers = unsafe { &*(sigval.sival_ptr as *const SignalPointers) };
            let wall_samples: &AtomicU32 = unsafe { signal_pointers.wall_samples.as_ref() };
            wall_samples.fetch_add(1, Ordering::SeqCst);

            let vm_interrupt: &AtomicBool = unsafe { signal_pointers.vm_interrupt.as_ref() };
            vm_interrupt.store(true, Ordering::SeqCst);
        }

        pub fn start(&self) -> anyhow::Result<()> {
            let cpu_result = self.cpu_timer.start(self.cpu_time_period_nanoseconds);
            let wall_result = self.wall_timer.start(self.wall_time_period_nanoseconds);
            cpu_result.and(wall_result)
        }

        pub fn stop(&self) -> anyhow::Result<()> {
            let cpu_result = self.cpu_timer.stop();
            let wall_result = self.wall_timer.stop();
            cpu_result.and(wall_result)
        }

        pub fn shutdown(&mut self) -> anyhow::Result<()> {
            let cpu_result = self.cpu_timer.shutdown();
            let wall_result = self.wall_timer.shutdown();
            cpu_result.and(wall_result)
        }
    }

    #[derive(Debug)]
    struct Timer {
        timerid: libc::timer_t,

        // Values below are mostly for debugging, they are not required.
        // They don't hurt much to keep around.
        #[allow(unused)]
        clockid: libc::clockid_t,
        #[allow(unused)]
        sigval: libc::sigval,
    }

    impl Timer {
        fn new(
            clockid: libc::clockid_t,
            sigval: libc::sigval,
            notify_function: extern "C" fn(libc::sigval),
        ) -> Self {
            let mut timer = Self {
                timerid: std::ptr::null_mut(),
                clockid,
                sigval,
            };

            let errno = unsafe {
                ddog_php_prof_timer_create(
                    &mut timer.timerid,
                    clockid,
                    timer.sigval,
                    notify_function,
                )
            };

            // Panic: if we cannot create a timer, I'm not sure how to handle that.
            _ = Self::handle_errno(errno, "failed to create Linux timer").unwrap();
            timer
        }

        fn start(&self, nanoseconds: u64) -> anyhow::Result<()> {
            let errno = unsafe { ddog_php_prof_timer_settime(self.timerid, nanoseconds) };
            Self::handle_errno(errno, "failed to start Linux timer for {nanoseconds} ns")
        }

        fn stop(&self) -> anyhow::Result<()> {
            let errno = unsafe { ddog_php_prof_timer_settime(self.timerid, 0) };
            Self::handle_errno(errno, "failed to stop Linux timer")
        }

        fn shutdown(&self) -> anyhow::Result<()> {
            let errno = unsafe { ddog_php_prof_timer_delete(self.timerid) };
            Self::handle_errno(errno, "failed to shutdown Linux timer")
        }

        fn handle_errno(errno: libc::c_int, context: &'static str) -> anyhow::Result<()> {
            if errno != 0 {
                let cstr = unsafe { CStr::from_ptr(libc::strerror(errno)) };
                Err(anyhow::anyhow!("{}", cstr.to_string_lossy()).context(context))
            } else {
                Ok(())
            }
        }
    }
}

#[cfg(not(target_os = "linux"))]
mod platform {
    use super::*;
    use crate::thread_utils::{join_timeout, spawn};
    use crossbeam_channel::{bounded, tick, Select, Sender};
    use log::warn;
    use std::thread::JoinHandle;
    use std::time::Duration;

    #[derive(Debug)]
    enum Message {
        Start,
        Stop,
        Shutdown,
    }

    pub struct Interrupter {
        sender: Sender<Message>,
        join_handle: Option<JoinHandle<()>>,
    }

    struct SendableSignalPointers {
        vm_interrupt: *const AtomicBool,
        cpu_samples: *const AtomicU32,
        wall_samples: *const AtomicU32,
    }

    impl From<SignalPointers> for SendableSignalPointers {
        fn from(pointers: SignalPointers) -> Self {
            Self {
                vm_interrupt: pointers.vm_interrupt.as_ptr(),
                cpu_samples: pointers.cpu_samples.as_ptr(),
                wall_samples: pointers.wall_samples.as_ptr(),
            }
        }
    }

    impl From<&SendableSignalPointers> for SignalPointers {
        fn from(sendable: &SendableSignalPointers) -> Self {
            Self {
                vm_interrupt: unsafe { &*sendable.vm_interrupt }.into(),
                cpu_samples: unsafe { &*sendable.cpu_samples }.into(),
                wall_samples: unsafe { &*sendable.wall_samples }.into(),
            }
        }
    }

    impl SendableSignalPointers {
        fn notify(&self) {
            let sp = SignalPointers::from(self);

            // It's not going to be accurate, but it's there.
            let cpu_samples: &AtomicU32 = unsafe { sp.cpu_samples.as_ref() };
            cpu_samples.fetch_add(1, Ordering::SeqCst);

            let wall_samples: &AtomicU32 = unsafe { sp.wall_samples.as_ref() };
            wall_samples.fetch_add(1, Ordering::SeqCst);

            let vm_interrupt: &AtomicBool = unsafe { sp.vm_interrupt.as_ref() };
            vm_interrupt.store(true, Ordering::SeqCst);
        }
    }

    /// # Safety
    /// Safe only because of the channel operations.
    unsafe impl Send for SendableSignalPointers {}

    impl Interrupter {
        pub fn new(
            signal_pointers: SignalPointers,
            _cpu_time_period_nanoseconds: u64,
            wall_time_period_nanoseconds: u64,
        ) -> Self {
            // > A special case is zero-capacity channel, which cannot hold
            // > any messages. Instead, send and receive operations must
            // > appear at the same time in order to pair up and pass the
            // > message over.
            let (sender, receiver) = bounded(0);
            let signal_pointers = SendableSignalPointers::from(signal_pointers);
            let join_handle = spawn("ddprof_time", move || {
                let thread = std::thread::current();
                let thread_name = thread.name().unwrap_or("{unknown}");

                let wall_interval = Duration::from_nanos(wall_time_period_nanoseconds);
                let ticker = tick(wall_interval);

                let mut selector = Select::new();
                assert_eq!(0, selector.recv(&receiver));
                let mut active = false;

                loop {
                    match selector.select() {
                        op if op.index() == 0 => match op.recv(&receiver) {
                            Ok(Message::Start) => {
                                if active {
                                    warn!("thread {thread_name} received start message but the timer was already started");
                                } else {
                                    active = true;
                                    assert_eq!(1, selector.recv(&ticker));
                                }
                            }
                            Ok(Message::Stop) => {
                                if active {
                                    selector.remove(1);
                                    active = false;
                                } else {
                                    warn!("thread {thread_name} received stop message but the timer wasn't running");
                                }
                            }
                            _ => {
                                break;
                            }
                        },

                        op if op.index() == 1 => match op.recv(&ticker) {
                            Ok(_instant) => {
                                // should always be true, just being careful.
                                if active {
                                    signal_pointers.notify()
                                }
                            }

                            // How does a timer fail?
                            Err(err) => {
                                warn!("{err:#}");
                                break;
                            }
                        },

                        _ => {
                            unreachable!("thread {} encountered unexpected operation", thread_name)
                        }
                    }
                }
            });

            Self {
                sender,
                join_handle: Some(join_handle),
            }
        }

        pub fn start(&self) -> anyhow::Result<()> {
            match self.sender.send(Message::Start) {
                Ok(_) => Ok(()),
                Err(err) => {
                    Err(anyhow::Error::from(err).context("failed to start TimeInterrupter"))
                }
            }
        }

        pub fn stop(&self) -> anyhow::Result<()> {
            match self.sender.send(Message::Stop) {
                Ok(_) => Ok(()),
                Err(err) => Err(anyhow::Error::from(err).context("failed to stop TimeInterrupter")),
            }
        }

        pub fn shutdown(&mut self) -> anyhow::Result<()> {
            if let Err(err) = self.sender.send(Message::Shutdown) {
                return Err(anyhow::Error::from(err).context("failed to shutdown TimeInterrupter"));
            }
            // todo: write impact
            let impact = "";

            let mut handle = None;
            std::mem::swap(&mut handle, &mut self.join_handle);
            if let Some(handle) = handle {
                join_timeout(handle, Duration::from_secs(2), impact);
            }
            Ok(())
        }
    }
}
