//! Platform-specific event loop implementations for the profiling thread.
//!
//! This module provides two implementations:
//! - **Linux**: Uses epoll to directly monitor timerfd and eventfd file descriptors
//!   for maximum efficiency with minimal context switches
//! - **Other platforms**: Uses crossbeam_channel::select! as a portable fallback
//!
//! The Linux implementation eliminates the overhead of:
//! - Background timer threads
//! - Extra channel send/recv operations for timer ticks
//! - thread::park_timeout calls
//!
//! Instead, it uses a single epoll_wait() that blocks on multiple file descriptors.

use crossbeam_channel::Receiver;
use std::time::{Duration, Instant};

#[cfg(target_os = "linux")]
use nix::sys::eventfd::{EfdFlags, EventFd};
#[cfg(target_os = "linux")]
use nix::sys::epoll::{Epoll, EpollCreateFlags, EpollEvent, EpollFlags, EpollTimeout};
#[cfg(target_os = "linux")]
use std::os::fd::AsRawFd;
#[cfg(target_os = "linux")]
use std::sync::atomic::{AtomicBool, Ordering};
#[cfg(target_os = "linux")]
use timerfd::{SetTimeFlags, TimerFd, TimerState};

use super::{ProfilerMessage, InterruptManager};

/// Events that can occur in the profiling event loop.
pub enum LoopEvent {
    /// A message was received from a PHP thread.
    Message(ProfilerMessage),
    /// The message channel was disconnected.
    MessageDisconnected,
    /// The wall-time timer fired (time to trigger interrupts).
    WallTimer,
    /// The upload timer fired (time to upload profiles).
    UploadTimer,
}

/// Platform-specific event loop state.
///
/// On Linux, this holds epoll/timerfd/eventfd file descriptors.
/// On other platforms, this stores the PeriodicTimer for wall-time sampling.
pub struct EventLoop {
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

    #[cfg(not(target_os = "linux"))]
    wall_timer: super::periodic_timer::PeriodicTimer,
}

impl EventLoop {
    /// Creates a new event loop.
    ///
    /// # Arguments
    /// * `wall_time_period` - Period for the wall-time timer (e.g., 10ms)
    /// * `upload_period` - Period for profile uploads (e.g., 60s)
    ///
    /// # Platform-specific behavior
    /// - **Linux**: Creates epoll, timerfd, and eventfd instances
    /// - **Other platforms**: No-op, returns empty state
    #[cfg(target_os = "linux")]
    pub fn new(wall_time_period: Duration, upload_period: Duration) -> anyhow::Result<Self> {
        use anyhow::Context;

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

        // Create eventfd for signaling message_receiver activity
        let message_event_fd = EventFd::from_flags(EfdFlags::EFD_NONBLOCK | EfdFlags::EFD_CLOEXEC)
            .context("Failed to create message eventfd")?;

        // Register timer_fd with epoll (initially not monitored, will be enabled conditionally)
        // We use EPOLLET (edge-triggered) to avoid repeated notifications
        let timer_event = EpollEvent::new(EpollFlags::EPOLLIN | EpollFlags::EPOLLET, 1);
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
            epoll,
            timer_fd,
            upload_timer_fd,
            message_event_fd,
            wall_timer_enabled: AtomicBool::new(false),
        })
    }

    #[cfg(not(target_os = "linux"))]
    pub fn new(wall_time_period: Duration, _upload_period: Duration) -> anyhow::Result<Self> {
        Ok(Self {
            wall_timer: super::periodic_timer::PeriodicTimer::new(wall_time_period),
        })
    }

    /// Returns an EventNotifier that can be used to signal when channels have data.
    ///
    /// This is used by the message sender to wake up the epoll_wait() when
    /// new messages arrive in the channel.
    #[cfg(target_os = "linux")]
    pub fn message_notifier(&self) -> EventNotifier {
        EventNotifier {
            event_fd: self.message_event_fd.as_raw_fd(),
        }
    }

    #[cfg(not(target_os = "linux"))]
    pub fn message_notifier(&self) -> EventNotifier {
        EventNotifier {}
    }


    /// Waits for the next event to occur.
    ///
    /// # Arguments
    /// * `message_receiver` - Channel for messages from PHP threads
    /// * `upload_tick` - Channel for upload timer ticks
    /// * `interrupt_manager` - Used to check if wall timer should be enabled
    ///
    /// # Returns
    /// The next event that occurred, or None if an error occurred.
    #[cfg(target_os = "linux")]
    pub fn wait_for_event(
        &self,
        message_receiver: &Receiver<ProfilerMessage>,
        _upload_tick: &Receiver<Instant>,
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
                    // Read from timerfd to clear it
                    let _ = self.timer_fd.read();
                    return Some(LoopEvent::WallTimer);
                }
                2 => {
                    // Upload timer fired
                    // Read from timerfd to clear it
                    let _ = self.upload_timer_fd.read();
                    return Some(LoopEvent::UploadTimer);
                }
                3 => {
                    // Message channel has data
                    // Read from eventfd to clear it
                    let _ = self.message_event_fd.read();

                    // Try to receive message from channel
                    match message_receiver.try_recv() {
                        Ok(msg) => return Some(LoopEvent::Message(msg)),
                        Err(crossbeam_channel::TryRecvError::Empty) => {
                            // False wakeup, continue to next event
                            continue;
                        }
                        Err(crossbeam_channel::TryRecvError::Disconnected) => {
                            return Some(LoopEvent::MessageDisconnected);
                        }
                    }
                }
                _ => {
                    log::warn!("Unexpected epoll user data: {}", user_data);
                }
            }
        }

        // No events processed, try again
        None
    }

    /// macOS fallback: Uses crossbeam_channel::select! for compatibility.
    ///
    /// This implementation has higher overhead due to park_timeout calls,
    /// but works on platforms without epoll support.
    #[cfg(not(target_os = "linux"))]
    pub fn wait_for_event(
        &self,
        message_receiver: &Receiver<ProfilerMessage>,
        upload_tick: &Receiver<Instant>,
        interrupt_manager: &InterruptManager,
    ) -> Option<LoopEvent> {
        let timer = if interrupt_manager.has_interrupts() {
            self.wall_timer.receiver()
        } else {
            &crossbeam_channel::never()
        };

        crossbeam_channel::select! {
            recv(message_receiver) -> result => {
                match result {
                    Ok(message) => Some(LoopEvent::Message(message)),
                    Err(_) => Some(LoopEvent::MessageDisconnected),
                }
            }
            recv(timer) -> _result => {
                Some(LoopEvent::WallTimer)
            }
            recv(upload_tick) -> result => {
                if result.is_ok() {
                    Some(LoopEvent::UploadTimer)
                } else {
                    None
                }
            }
        }
    }
}

/// A notifier that can signal the event loop when data is available.
///
/// On Linux, this wraps an eventfd file descriptor.
/// On other platforms, this is a no-op.
pub struct EventNotifier {
    #[cfg(target_os = "linux")]
    event_fd: std::os::fd::RawFd,
}

impl EventNotifier {
    /// Signals the event loop that an event has occurred.
    ///
    /// This is called after sending to a crossbeam channel to wake up epoll_wait().
    #[cfg(target_os = "linux")]
    pub fn notify(&self) {
        use nix::unistd::write;
        use std::os::fd::BorrowedFd;
        // Write 1 to the eventfd to signal the event
        let buf = 1u64.to_ne_bytes();
        // SAFETY: event_fd is valid for the lifetime of EventLoop
        let borrowed = unsafe { BorrowedFd::borrow_raw(self.event_fd) };
        let _ = write(borrowed, &buf);
    }

    #[cfg(not(target_os = "linux"))]
    pub fn notify(&self) {
        // No-op on non-Linux platforms
    }
}

// Allow EventNotifier to be safely sent between threads
unsafe impl Send for EventNotifier {}
unsafe impl Sync for EventNotifier {}
