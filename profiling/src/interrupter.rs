use std::ptr::NonNull;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};

// TODO: support forking

#[derive(Debug, Eq, PartialEq)]
pub struct SignalPointers {
    pub wall_samples: NonNull<AtomicU32>,
    pub vm_interrupt: NonNull<AtomicBool>,
}

pub trait Interrupter {
    fn start(&self) -> anyhow::Result<()>;
    fn stop(&self) -> anyhow::Result<()>;
    fn shutdown(&mut self) -> anyhow::Result<()>;
}

pub fn interrupter(
    signal_pointers: SignalPointers,
    wall_time_period_nanoseconds: u64,
) -> Box<dyn Interrupter> {
    Box::new(crossbeam::Interrupter::new(
        signal_pointers,
        wall_time_period_nanoseconds,
    ))
}

mod crossbeam {
    use super::*;
    use crate::thread_utils::{join_timeout, spawn};
    use crossbeam_channel::{bounded, select, tick, Sender};
    use log::{trace, warn};
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
        wall_samples: *const AtomicU32,
    }

    impl From<SignalPointers> for SendableSignalPointers {
        fn from(pointers: SignalPointers) -> Self {
            Self {
                vm_interrupt: pointers.vm_interrupt.as_ptr(),
                wall_samples: pointers.wall_samples.as_ptr(),
            }
        }
    }

    impl From<&SendableSignalPointers> for SignalPointers {
        fn from(sendable: &SendableSignalPointers) -> Self {
            Self {
                vm_interrupt: unsafe { &*sendable.vm_interrupt }.into(),
                wall_samples: unsafe { &*sendable.wall_samples }.into(),
            }
        }
    }

    impl SendableSignalPointers {
        fn notify(&self) {
            let sp = SignalPointers::from(self);

            let wall_samples: &AtomicU32 = unsafe { sp.wall_samples.as_ref() };
            wall_samples.fetch_add(1, Ordering::SeqCst);

            // Trigger the VM interrupt after the others.
            let vm_interrupt: &AtomicBool = unsafe { sp.vm_interrupt.as_ref() };
            vm_interrupt.store(true, Ordering::SeqCst);
        }
    }

    /// # Safety
    /// Safe only because of the channel operations.
    unsafe impl Send for SendableSignalPointers {}

    impl Interrupter {
        const THREAD_NAME: &'static str = "ddprof_time";

        pub fn new(signal_pointers: SignalPointers, wall_time_period_nanoseconds: u64) -> Self {
            // > A special case is zero-capacity channel, which cannot hold
            // > any messages. Instead, send and receive operations must
            // > appear at the same time in order to pair up and pass the
            // > message over.
            let (sender, receiver) = bounded(0);
            let signal_pointers = SendableSignalPointers::from(signal_pointers);
            let join_handle = spawn(Self::THREAD_NAME, move || {
                let thread = std::thread::current();
                let thread_name = thread.name().unwrap_or("{unknown}");

                let wall_interval = Duration::from_nanos(wall_time_period_nanoseconds);
                let ticker = tick(wall_interval);
                let mut active = false;

                // The goal is to wait on ticker if the associated PHP thread
                // is serving a request. A tick or two after it ends may occur,
                // but if the PHP thread is idle, like if it's being kept open
                // with Connection: Keep-Alive, then we really need to limit
                // how much we wake up.

                // The code below has two select!s, where one of the recv's is
                // the same -- the code to handle receiving messages. The
                // difference is we only recv on the ticker if we're in an
                // `active` state.
                loop {
                    if active {
                        select! {
                            // Remember to duplicate any changes to the else
                            // branch below.
                            recv(receiver) -> message => match message {
                                Ok(Message::Start) => {
                                    active = true;
                                }
                                Ok(Message::Stop) => {
                                    active = false;
                                }
                                _ => {
                                    break;
                                }
                            },

                            recv(ticker) -> message => match message {
                                Ok(_) => signal_pointers.notify(),

                                // How does a timer fail?
                                Err(err) => {
                                    warn!("{err:#}");
                                    break;
                                }
                            },
                        }
                    } else {
                        // It's not great that we duplicate this code, but it
                        // was the only way I could get it to compile.
                        select! {
                            recv(receiver) -> message => match message {
                                Ok(Message::Start) => {
                                    active = true;
                                }
                                Ok(Message::Stop) => {
                                    active = false;
                                }
                                _ => {
                                    break;
                                }
                            },
                        }
                    }
                }

                trace!("thread {thread_name} shut down");
            });

            Self {
                sender,
                join_handle: Some(join_handle),
            }
        }
    }

    impl super::Interrupter for Interrupter {
        fn start(&self) -> anyhow::Result<()> {
            self.sender.send(Message::Start).map_err(|err| {
                anyhow::Error::from(err).context(format!("failed to start {}", Self::THREAD_NAME))
            })
        }

        fn stop(&self) -> anyhow::Result<()> {
            self.sender.send(Message::Stop).map_err(|err| {
                anyhow::Error::from(err).context(format!("failed to stop {}", Self::THREAD_NAME))
            })
        }

        fn shutdown(&mut self) -> anyhow::Result<()> {
            if let Err(err) = self.sender.send(Message::Shutdown) {
                return Err(anyhow::Error::from(err)
                    .context(format!("failed to shutdown {}", Self::THREAD_NAME)));
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
