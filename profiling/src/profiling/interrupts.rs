use crossbeam_channel::{SendTimeoutError, Sender};
use std::collections::HashSet;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Barrier, Mutex};
use std::thread::JoinHandle;
use std::time::Duration;

#[derive(Debug, Eq, PartialEq, Hash)]
pub struct VmInterrupt {
    pub interrupt_count_ptr: *const AtomicU32,
    pub engine_ptr: *const AtomicBool,
}

impl std::fmt::Display for VmInterrupt {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "VmInterrupt{{{:?}, {:?}}}",
            self.interrupt_count_ptr, self.engine_ptr
        )
    }
}

// This is a lie, technically, but we're trying to build it safely on top of
// the PHP VM.
unsafe impl Send for VmInterrupt {}

pub(super) struct InterruptManager {
    vm_interrupts: Arc<Mutex<HashSet<VmInterrupt>>>,
    async_sender: Sender<AsyncMessage>,
    sync_sender: Sender<SyncMessage>,
    join_handle: JoinHandle<()>,
}

enum AsyncMessage {
    /// Used to wake the helper thread so it can synchronize the fact a
    /// request is being served.
    Wake,
}

enum SyncMessage {
    /// Used to put the helper thread into a barrier for caller so it can fork.
    Pause,

    /// Used to shut down the helper thread.
    Shutdown,
}

impl InterruptManager {
    const THREAD_NAME: &'static str = "ddprof_time";

    pub(super) fn new(barrier: Arc<Barrier>) -> Self {
        let (sync_sender, sync_receiver) = crossbeam_channel::bounded(0);

        // The 7 is arbitrary.
        let (async_sender, async_receiver) = crossbeam_channel::bounded(7);

        // Capacity 1 because we expect there to be 1 thread in NTS mode, and
        // if it happens to be ZTS this is just an initial capacity anyway.
        let vm_interrupts = Arc::new(Mutex::new(HashSet::with_capacity(1)));
        Self {
            vm_interrupts: vm_interrupts.clone(),
            async_sender,
            sync_sender,
            join_handle: super::thread_utils::spawn(Self::THREAD_NAME, move || {
                let wall_timer = crossbeam_channel::tick(super::WALL_TIME_PERIOD);
                let never = crossbeam_channel::never();
                loop {
                    // The crossbeam_channel::select! doesn't have the ability
                    // to optionally recv something. Instead, if the tick
                    // channel shouldn't be selected on, then pass the never
                    // channel. Since the never channel will never be ready,
                    // this effectively makes that branch optional for that
                    // loop iteration.
                    let timer = if !vm_interrupts.lock().unwrap().is_empty() {
                        &wall_timer
                    } else {
                        &never
                    };

                    crossbeam_channel::select! {
                        recv(async_receiver) -> message => match message {
                            // This message is just to wake the thread up
                            // so it can sync `active_interrupts`.
                            Ok(AsyncMessage::Wake) => {},
                            Err(err) => {
                                log::warn!("{err}");
                                break;
                            },
                        },

                        recv(sync_receiver) -> message => match message {
                            Ok(SyncMessage::Pause) => {
                                // First, wait for every thread to finish what
                                // they are currently doing.
                                barrier.wait();
                                // Then, wait for the fork to be completed.
                                barrier.wait();
                            },

                            Ok(SyncMessage::Shutdown) => break,
                            Err(err) => {
                                log::warn!("{err}");
                                break;
                            },
                        },

                        recv(timer) -> message => match message {
                            Ok(_) => {
                                let vm_interrupts = vm_interrupts.lock().unwrap();
                                vm_interrupts.iter().for_each(|obj| unsafe {
                                    (*obj.interrupt_count_ptr).fetch_add(1, Ordering::SeqCst);
                                    (*obj.engine_ptr).store(true, Ordering::SeqCst);
                                });
                            },

                            Err(err) => {
                                log::warn!("{err}");
                                break;
                            },
                        }
                    }
                }

                log::trace!("thread {} shut down", Self::THREAD_NAME);
            }),
        }
    }

    /// Remove the interrupt from the manager's set and attempt to wake its
    /// helper thread so it can synchronize the state of active PHP requests.
    pub(super) fn add_interrupt(&self, interrupt: VmInterrupt) {
        // First, add the interrupt to the set.
        {
            let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
            vm_interrupts.insert(interrupt);
        }

        // Second, make a best-effort attempt to wake the helper thread so
        // that it is aware another PHP request is in flight.
        if self.async_sender.try_send(AsyncMessage::Wake).is_err() {
            // If  it's full, just stop trying. This likely means the thread is
            // behind or crashed. Either way, no sense trying to wake it.
            // If it's disconnected, there's nothing to do (again, the thread
            // has likely crashed).
        }
    }

    /// Remove the interrupt from the manager's set.
    pub(super) fn remove_interrupt(&self, interrupt: VmInterrupt) {
        // First, remove the interrupt from the set.
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.remove(&interrupt);

        // Second, do not try to wake the helper thread. In NTS mode, the next
        // request may come before the timer expires anyway, and if not, at
        // worst we had 1 wake-up outside of a request, which is the same as
        // if we wake it now.
        // In ZTS mode, this would just be unnecessary wake-ups, as there are
        // likely to be other threads serving requests.
    }

    /// Pause the interrupter so the main thread can fork.
    pub(super) fn pause(&self) -> anyhow::Result<()> {
        self.sync_sender.send(SyncMessage::Pause).map_err(|err| {
            anyhow::Error::from(err).context(format!("failed to pause {}", Self::THREAD_NAME))
        })
    }

    /// Shut down the interrupter.
    pub(super) fn shutdown(self) -> anyhow::Result<()> {
        match self
            .sync_sender
            .send_timeout(SyncMessage::Shutdown, Duration::from_secs(2))
        {
            // If the channel operation was a success, or if it's already
            // disconnected, then join on the thread.
            Ok(_) => {}
            Err(SendTimeoutError::Disconnected(_message)) => {}

            // However, if it timed out, we cannot safely join the thread.
            Err(SendTimeoutError::Timeout(_message)) => {
                anyhow::bail!(
                    "timeout reached when trying to send shutdown message to {}",
                    Self::THREAD_NAME
                );
            }
        }

        let impact = "";
        super::thread_utils::join_timeout(self.join_handle, Duration::from_secs(2), impact);
        Ok(())
    }
}
