use cpu_time::ThreadTime;
use std::time::Instant;

pub struct Clocks {
    pub initialized: bool,
    pub cpu_time: Option<ThreadTime>,
    pub wall_time: Instant,
}

impl Clocks {
    pub fn initialize(&mut self, cpu_time_enabled: bool) {
        if self.initialized {
            return;
        }
        self.wall_time = Instant::now();
        self.cpu_time = if cpu_time_enabled {
            ThreadTime::try_now().ok()
        } else {
            None
        };
        self.initialized = true;
    }

    #[inline(always)]
    fn cpu_sub(now: ThreadTime, prev: ThreadTime) -> i64 {
        let now = now.as_duration();
        let prev = prev.as_duration();

        match now.checked_sub(prev) {
            // If a 128 bit value doesn't fit in 64 bits, use the max.
            Some(duration) => duration.as_nanos().try_into().unwrap_or(i64::MAX),

            // If this happened, then either the programmer screwed up and
            // passed args in backwards, or cpu time has gone backward... ish.
            // Supposedly it can happen if the thread migrates CPUs:
            // https://www.percona.com/blog/what-time-18446744073709550000-means/
            // Regardless of why, a customer hit this:
            // https://github.com/DataDog/dd-trace-php/issues/1880
            // In these cases, zero is much closer to reality than i64::MAX.
            None => 0,
        }
    }

    pub fn rotate_wall_clock(&mut self) -> i64 {
        let wall_now = Instant::now();
        let wall_time = wall_now.duration_since(self.wall_time);
        self.wall_time = wall_now;
        wall_time.as_nanos().try_into().unwrap_or(i64::MAX)
    }

    pub fn rotate_cpu_clock(&mut self) -> i64 {
        // If CPU time is disabled, or if it's enabled but not available on the
        // platform, then `self.cpu_time` will be None.
        if let Some(last_cpu_time) = self.cpu_time {
            let now = ThreadTime::try_now()
                .expect("CPU time to work since it's worked before during this process");
            let cpu_time = Self::cpu_sub(now, last_cpu_time);
            self.cpu_time = Some(now);
            cpu_time
        } else {
            0
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    #[test]
    fn worker_clock_is_initialized_only_once() {
        let initial_wall_time = Instant::now() - Duration::from_secs(2);
        let mut clocks = Clocks {
            initialized: false,
            cpu_time: None,
            wall_time: initial_wall_time,
        };

        clocks.initialize(false);
        let worker_wall_time = clocks.wall_time;
        assert!(worker_wall_time > initial_wall_time);

        clocks.initialize(false);

        assert_eq!(clocks.wall_time, worker_wall_time);
    }
}
