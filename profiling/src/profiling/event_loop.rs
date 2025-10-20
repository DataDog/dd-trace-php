//! Platform-specific event loop implementations for the profiling thread.
//!
//! This module provides lock-free, zero-overhead event loops:
//! - **Linux**: Uses epoll to directly monitor timerfd and eventfd file descriptors
//! - **macOS**: Uses kqueue to monitor timers and kevent notifications
//!
//! Both implementations eliminate ALL park/unpark overhead by:
//! - Using lock-free ArrayQueue instead of crossbeam channels
//! - Direct OS event monitoring (epoll/kqueue)
//! - No background threads
//! - No thread::park_timeout calls

use crossbeam_queue::ArrayQueue;
use std::sync::Arc;
use std::sync::atomic::Ordering;
use std::time::Duration;

#[cfg(target_os = "linux")]
use nix::sys::eventfd::{EfdFlags, EventFd};
#[cfg(target_os = "linux")]
use nix::sys::epoll::{Epoll, EpollCreateFlags, EpollEvent, EpollFlags, EpollTimeout};
#[cfg(target_os = "linux")]
use std::os::fd::AsRawFd;
#[cfg(target_os = "linux")]
use std::sync::atomic::AtomicBool;
#[cfg(target_os = "linux")]
use timerfd::{SetTimeFlags, TimerFd, TimerState};

#[cfg(target_os = "macos")]
use nix::sys::event::{EventFilter, EventFlag, FilterFlag, Kqueue, KEvent};
#[cfg(target_os = "macos")]
use std::os::fd::{AsRawFd, RawFd};

use super::{ProfilerMessage, InterruptManager};

/// Events that can occur in the profiling event loop.
pub enum LoopEvent {
    /// A message was received from a PHP thread.
    Message(ProfilerMessage),
    /// The wall-time timer fired (time to trigger interrupts).
    WallTimer,
    /// The upload timer fired (time to upload profiles).
    UploadTimer,
}

/// Platform-specific event loop state.
pub struct EventLoop {
    message_queue: Arc<ArrayQueue<ProfilerMessage>>,

    #[cfg(target_os = "linux")]
    epoll: Epoll,
    #[cfg(target_os = "linux")]
    timer_fd: TimerFd,
    #[cfg(target_os = "linux")]
    upload_timer_fd: TimerFd,
    #[cfg(target_os = "linux")]
    message_event_fd: EventFd,
    #[cfg(target_os = "linux")]
    wall_timer_enabled: AtomicBool,

    #[cfg(target_os = "macos")]
    kqueue: Kqueue,
    #[cfg(target_os = "macos")]
    wall_timer_ident: usize,
    #[cfg(target_os = "macos")]
    upload_timer_ident: usize,
    #[cfg(target_os = "macos")]
    message_pipe_fd: RawFd,
}

impl EventLoop {
    /// Creates a new event loop with a message queue.
    ///
    /// # Arguments
    /// * `wall_time_period` - Period for the wall-time timer (e.g., 10ms)
    /// * `upload_period` - Period for profile uploads (e.g., 60s)
    /// * `queue_capacity` - Capacity of the message queue
    ///
    /// # Returns
    /// Returns the EventLoop and the message queue Arc for senders to use
    #[cfg(target_os = "linux")]
    pub fn new(
        wall_time_period: Duration,
        upload_period: Duration,
        queue_capacity: usize,
    ) -> anyhow::Result<Self> {
        use anyhow::Context;

        let message_queue = Arc::new(ArrayQueue::new(queue_capacity));

        // Create epoll instance
        let epoll = Epoll::new(EpollCreateFlags::EPOLL_CLOEXEC)
            .context("Failed to create epoll instance")?;

        // Create timerfd for wall-time sampling (10ms periodic)
        let mut timer_fd = TimerFd::new().context("Failed to create timerfd")?;
        timer_fd.set_state(
            TimerState::Periodic {
                current: wall_time_period,
                interval: wall_time_period,
            },
            SetTimeFlags::Default,
        );

        // Create timerfd for upload period
        let mut upload_timer_fd = TimerFd::new().context("Failed to create upload timerfd")?;
        upload_timer_fd.set_state(
            TimerState::Periodic {
                current: upload_period,
                interval: upload_period,
            },
            SetTimeFlags::Default,
        );

        // Create eventfd for signaling message queue activity
        let message_event_fd = EventFd::from_flags(EfdFlags::EFD_NONBLOCK | EfdFlags::EFD_CLOEXEC)
            .context("Failed to create message eventfd")?;

        // Register timer_fd with epoll (user data = 1, initially disabled)
        let timer_event = EpollEvent::new(EpollFlags::empty(), 1);
        epoll
            .add(&timer_fd, timer_event)
            .context("Failed to add timerfd to epoll")?;

        // Register upload_timer_fd with epoll (user data = 2)
        let upload_timer_event = EpollEvent::new(EpollFlags::EPOLLIN | EpollFlags::EPOLLET, 2);
        epoll
            .add(&upload_timer_fd, upload_timer_event)
            .context("Failed to add upload timerfd to epoll")?;

        // Register message_event_fd with epoll (user data = 3)
        let message_event = EpollEvent::new(EpollFlags::EPOLLIN | EpollFlags::EPOLLET, 3);
        epoll
            .add(&message_event_fd, message_event)
            .context("Failed to add message eventfd to epoll")?;

        Ok(Self {
            message_queue,
            epoll,
            timer_fd,
            upload_timer_fd,
            message_event_fd,
            wall_timer_enabled: AtomicBool::new(false),
        })
    }

    /// macOS implementation using kqueue
    #[cfg(target_os = "macos")]
    pub fn new(
        wall_time_period: Duration,
        upload_period: Duration,
        queue_capacity: usize,
    ) -> anyhow::Result<Self> {
        use anyhow::Context;
        use nix::unistd::pipe;

        let message_queue = Arc::new(ArrayQueue::new(queue_capacity));

        // Create kqueue instance
        let kqueue = Kqueue::new().context("Failed to create kqueue")?;

        // Create pipe for message notifications
        let (read_fd, write_fd) = pipe().context("Failed to create pipe")?;

        // Set non-blocking on read end
        use nix::fcntl::{fcntl, FcntlArg, OFlag};
        let read_raw = read_fd.as_raw_fd();
        let write_raw = write_fd.as_raw_fd();
        let flags = fcntl(read_raw, FcntlArg::F_GETFL).context("Failed to get pipe flags")?;
        let flags = OFlag::from_bits_truncate(flags) | OFlag::O_NONBLOCK;
        fcntl(read_raw, FcntlArg::F_SETFL(flags)).context("Failed to set pipe non-blocking")?;

        // Register pipe read end with kqueue
        let pipe_event = KEvent::new(
            read_raw as usize,
            EventFilter::EVFILT_READ,
            EventFlag::EV_ADD | EventFlag::EV_CLEAR,
            FilterFlag::empty(),
            0,
            0,
        );
        kqueue.kevent(&[pipe_event], &mut [], None)
            .context("Failed to register pipe with kqueue")?;

        // Register wall timer (initially disabled, ident=1)
        let wall_timer_millis = wall_time_period.as_millis() as isize;
        let wall_timer_event = KEvent::new(
            1,
            EventFilter::EVFILT_TIMER,
            EventFlag::EV_ADD | EventFlag::EV_DISABLE,
            FilterFlag::empty(),
            wall_timer_millis,
            0,
        );
        kqueue.kevent(&[wall_timer_event], &mut [], None)
            .context("Failed to register wall timer with kqueue")?;

        // Register upload timer (ident=2)
        let upload_millis = upload_period.as_millis() as isize;
        let upload_timer_event = KEvent::new(
            2,
            EventFilter::EVFILT_TIMER,
            EventFlag::EV_ADD | EventFlag::EV_ENABLE,
            FilterFlag::empty(),
            upload_millis,
            0,
        );
        kqueue.kevent(&[upload_timer_event], &mut [], None)
            .context("Failed to register upload timer with kqueue")?;

        // Store write_fd in a static for the notifier
        // This is safe because EventLoop is created once and lives for program duration
        unsafe {
            MACOS_MESSAGE_PIPE_WRITE_FD = write_raw;
        }

        Ok(Self {
            message_queue,
            kqueue,
            wall_timer_ident: 1,
            upload_timer_ident: 2,
            message_pipe_fd: read_raw,
        })
    }

    /// Returns the message queue for senders to push to
    pub fn message_queue(&self) -> Arc<ArrayQueue<ProfilerMessage>> {
        Arc::clone(&self.message_queue)
    }

    /// Returns an EventNotifier that can signal when messages are queued
    #[cfg(target_os = "linux")]
    pub fn message_notifier(&self) -> EventNotifier {
        EventNotifier {
            event_fd: self.message_event_fd.as_raw_fd(),
        }
    }

    #[cfg(target_os = "macos")]
    pub fn message_notifier(&self) -> EventNotifier {
        EventNotifier {}
    }

    /// Waits for the next event to occur.
    ///
    /// # Linux implementation
    /// Uses epoll_wait() to monitor timerfd and eventfd
    #[cfg(target_os = "linux")]
    pub fn wait_for_event(
        &self,
        interrupt_manager: &InterruptManager,
    ) -> Option<LoopEvent> {
        // Update wall timer state based on whether interrupts are registered
        let should_enable = interrupt_manager.has_interrupts();
        let was_enabled = self.wall_timer_enabled.load(Ordering::Relaxed);
        if should_enable != was_enabled {
            self.wall_timer_enabled.store(should_enable, Ordering::Relaxed);

            // Enable or disable the timer by modifying its epoll flags
            let flags = if should_enable {
                EpollFlags::EPOLLIN | EpollFlags::EPOLLET
            } else {
                EpollFlags::empty()
            };

            let mut event = EpollEvent::new(flags, 1);
            if let Err(e) = self.epoll.modify(&self.timer_fd, &mut event) {
                log::warn!("Failed to modify timerfd epoll flags: {}", e);
            }
        }

        // Wait for events (block indefinitely)
        let mut events = [EpollEvent::empty(); 8];
        let num_events = match self.epoll.wait(&mut events, EpollTimeout::NONE) {
            Ok(n) => n,
            Err(e) => {
                log::error!("epoll_wait failed: {}", e);
                return None;
            }
        };

        // Process events in order of priority
        for i in 0..num_events {
            let event = events[i];
            let user_data = event.data();

            match user_data {
                1 => {
                    // Wall timer fired
                    let _ = self.timer_fd.read();
                    return Some(LoopEvent::WallTimer);
                }
                2 => {
                    // Upload timer fired
                    let _ = self.upload_timer_fd.read();
                    return Some(LoopEvent::UploadTimer);
                }
                3 => {
                    // Message queue has data
                    let _ = self.message_event_fd.read();

                    // Drain all messages from the lock-free queue
                    if let Some(msg) = self.message_queue.pop() {
                        return Some(LoopEvent::Message(msg));
                    }
                }
                _ => {
                    log::warn!("Unexpected epoll user data: {}", user_data);
                }
            }
        }

        None
    }

    /// macOS implementation using kqueue
    #[cfg(target_os = "macos")]
    pub fn wait_for_event(
        &self,
        interrupt_manager: &InterruptManager,
    ) -> Option<LoopEvent> {
        use nix::unistd::read;

        // Check if we need to enable/disable wall timer
        static WALL_TIMER_ENABLED: std::sync::atomic::AtomicBool = std::sync::atomic::AtomicBool::new(false);
        let should_enable = interrupt_manager.has_interrupts();
        let was_enabled = WALL_TIMER_ENABLED.load(Ordering::Relaxed);

        if should_enable != was_enabled {
            WALL_TIMER_ENABLED.store(should_enable, Ordering::Relaxed);

            let flags = if should_enable {
                EventFlag::EV_ENABLE
            } else {
                EventFlag::EV_DISABLE
            };

            let event = KEvent::new(
                self.wall_timer_ident,
                EventFilter::EVFILT_TIMER,
                flags,
                FilterFlag::empty(),
                0,
                0,
            );

            if let Err(e) = self.kqueue.kevent(&[event], &mut [], None) {
                log::warn!("Failed to modify wall timer: {}", e);
            }
        }

        // Wait for events
        let mut events = [KEvent::new(0, EventFilter::EVFILT_READ, EventFlag::empty(), FilterFlag::empty(), 0, 0); 8];
        let num_events = match self.kqueue.kevent(&[], &mut events, None) {
            Ok(n) => n,
            Err(e) => {
                log::error!("kevent failed: {}", e);
                return None;
            }
        };

        // Process events
        for i in 0..num_events {
            let event = events[i];

            match event.filter() {
                Ok(EventFilter::EVFILT_TIMER) => {
                    if event.ident() == self.wall_timer_ident {
                        return Some(LoopEvent::WallTimer);
                    } else if event.ident() == self.upload_timer_ident {
                        return Some(LoopEvent::UploadTimer);
                    }
                }
                Ok(EventFilter::EVFILT_READ) => {
                    // Pipe notification - drain it
                    let mut buf = [0u8; 8];
                    let _ = read(self.message_pipe_fd, &mut buf);

                    // Drain messages from queue
                    if let Some(msg) = self.message_queue.pop() {
                        return Some(LoopEvent::Message(msg));
                    }
                }
                _ => {
                    // Ignore other event types
                }
            }
        }

        None
    }
}

/// A notifier that can signal the event loop when data is available.
pub struct EventNotifier {
    #[cfg(target_os = "linux")]
    event_fd: std::os::fd::RawFd,
}

#[cfg(target_os = "macos")]
static mut MACOS_MESSAGE_PIPE_WRITE_FD: RawFd = -1;

impl EventNotifier {
    /// Signals the event loop that an event has occurred.
    ///
    /// This is called after pushing to the queue to wake up epoll_wait/kevent.
    #[cfg(target_os = "linux")]
    pub fn notify(&self) {
        use nix::unistd::write;
        use std::os::fd::BorrowedFd;

        let buf = 1u64.to_ne_bytes();
        // SAFETY: event_fd is valid for the lifetime of EventLoop
        let borrowed = unsafe { BorrowedFd::borrow_raw(self.event_fd) };
        let _ = write(borrowed, &buf);
    }

    #[cfg(target_os = "macos")]
    pub fn notify(&self) {
        use nix::unistd::write;
        use std::os::fd::BorrowedFd;

        unsafe {
            if MACOS_MESSAGE_PIPE_WRITE_FD >= 0 {
                let buf = [1u8];
                let borrowed = BorrowedFd::borrow_raw(MACOS_MESSAGE_PIPE_WRITE_FD);
                let _ = write(borrowed, &buf);
            }
        }
    }
}

// Allow EventNotifier to be safely sent between threads
unsafe impl Send for EventNotifier {}
unsafe impl Sync for EventNotifier {}
