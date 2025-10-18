//! Platform-specific periodic timer implementation.
//!
//! On Linux, uses timerfd for efficient kernel-managed timing with lower overhead.
//! On other platforms (macOS, etc.), falls back to crossbeam channel tick.

use crossbeam_channel::Receiver;
use std::time::{Duration, Instant};

/// A periodic timer that provides tick events via a channel receiver.
///
/// This abstraction uses different implementations based on the target platform:
/// - Linux: Uses timerfd with a background thread for efficient kernel-managed timing
/// - Other platforms: Uses crossbeam_channel::tick as a simple fallback
pub struct PeriodicTimer {
    receiver: Receiver<Instant>,
    #[cfg(target_os = "linux")]
    _thread_handle: Option<std::thread::JoinHandle<()>>,
}

impl PeriodicTimer {
    /// Creates a new periodic timer that ticks at the specified interval.
    ///
    /// # Arguments
    /// * `duration` - The interval between timer ticks
    ///
    /// # Platform-specific behavior
    /// - **Linux**: Creates a timerfd and spawns a background thread to forward ticks
    /// - **Other platforms**: Uses crossbeam_channel::tick directly
    pub fn new(duration: Duration) -> Self {
        #[cfg(target_os = "linux")]
        {
            Self::new_timerfd(duration)
        }

        #[cfg(not(target_os = "linux"))]
        {
            Self::new_crossbeam(duration)
        }
    }

    /// Returns a reference to the receiver that yields `Instant` values on each tick.
    ///
    /// This receiver can be used in crossbeam_channel::select! operations.
    pub fn receiver(&self) -> &Receiver<Instant> {
        &self.receiver
    }

    /// Linux implementation using timerfd.
    ///
    /// Creates a timerfd configured for periodic operation and spawns a background
    /// thread that reads from the timer and forwards ticks to a channel.
    #[cfg(target_os = "linux")]
    fn new_timerfd(duration: Duration) -> Self {
        use timerfd::{SetTimeFlags, TimerFd, TimerState};

        let (sender, receiver) = crossbeam_channel::bounded(1);

        // Create and configure the timerfd
        let mut timer = TimerFd::new().expect("Failed to create timerfd");
        timer.set_state(
            TimerState::Periodic {
                current: duration,
                interval: duration,
            },
            SetTimeFlags::Default,
        );

        // Spawn a background thread to read from timerfd and forward to channel
        let thread_handle = std::thread::Builder::new()
            .name("profiling-timer".to_string())
            .spawn(move || {
                loop {
                    // Block until the timer expires
                    // read() returns the number of expirations as u64
                    let _expirations = timer.read();

                    // Send the current instant to the channel
                    // If the receiver is dropped, the thread will exit
                    if sender.send(Instant::now()).is_err() {
                        break;
                    }
                }
            })
            .expect("Failed to spawn timer thread");

        Self {
            receiver,
            _thread_handle: Some(thread_handle),
        }
    }

    /// Fallback implementation using crossbeam_channel::tick.
    ///
    /// This is used on platforms that don't support timerfd (e.g., macOS).
    /// The crossbeam tick implementation uses thread::sleep internally.
    #[cfg(not(target_os = "linux"))]
    fn new_crossbeam(duration: Duration) -> Self {
        let receiver = crossbeam_channel::tick(duration);
        Self { receiver }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    #[test]
    fn test_periodic_timer_basic() {
        let timer = PeriodicTimer::new(Duration::from_millis(10));
        let receiver = timer.receiver();

        // Wait for first tick
        let instant1 = receiver.recv().expect("Should receive first tick");

        // Wait for second tick
        let instant2 = receiver.recv().expect("Should receive second tick");

        // Verify that ticks are approximately 10ms apart
        let elapsed = instant2.duration_since(instant1);
        assert!(
            elapsed >= Duration::from_millis(8) && elapsed <= Duration::from_millis(15),
            "Expected ~10ms between ticks, got {:?}",
            elapsed
        );
    }

    #[test]
    fn test_periodic_timer_select() {
        let timer = PeriodicTimer::new(Duration::from_millis(10));
        let receiver = timer.receiver();

        // Test that the receiver works with crossbeam select
        crossbeam_channel::select! {
            recv(receiver) -> result => {
                assert!(result.is_ok(), "Should receive tick via select");
            }
        }
    }
}
