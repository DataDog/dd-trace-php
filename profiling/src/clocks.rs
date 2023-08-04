use cpu_time::ThreadTime;
use std::time::Instant;

pub struct Clocks {
    pub cpu_time: Option<ThreadTime>,
    pub wall_time: Instant,
}

impl Clocks {
    pub fn initialize(&mut self, cpu_time_enabled: bool) {
        self.wall_time = Instant::now();
        self.cpu_time = if cpu_time_enabled {
            ThreadTime::try_now().ok()
        } else {
            None
        };
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

    pub fn rotate_clocks(&mut self) -> (i64, i64) {
        let wall_now = Instant::now();
        let wall_time = wall_now.duration_since(self.wall_time);
        self.wall_time = wall_now;
        let wall_time: i64 = wall_time.as_nanos().try_into().unwrap_or(i64::MAX);

        // If CPU time is disabled, or if it's enabled but not available on the
        // platform, then `self.cpu_time` will be None.
        let cpu_time = if let Some(last_cpu_time) = self.cpu_time {
            let now = ThreadTime::try_now()
                .expect("CPU time to work since it's worked before during this process");
            let cpu_time = Self::cpu_sub(now, last_cpu_time);
            self.cpu_time = Some(now);
            cpu_time
        } else {
            0
        };
        (wall_time, cpu_time)
    }
}
