use std::{
    mem::MaybeUninit,
    sync::atomic::{AtomicU64, Ordering},
};

pub(super) struct Limiter {
    max_per_second: u32,
    counts: AtomicU64,
}

impl Limiter {
    pub(super) fn new(max_per_second: u32) -> Self {
        Limiter {
            max_per_second,
            counts: 0_u64.into(),
        }
    }

    pub(super) fn go_through(&self) -> bool {
        if self.max_per_second == 0 {
            return true;
        }

        let mut now_ms = monotonic_time_millis() as u32;
        let mut now_sec = ((now_ms / 1000) & 0xFFF) as u16;

        let mut prev = self.counts.load(Ordering::Relaxed);
        loop {
            let (st_sec, st_cur, st_prev) = split_u64(prev);

            let mut cur_count = st_cur;
            let mut prev_count = st_prev;

            if now_sec != st_sec {
                if st_sec == now_sec - 1 {
                    prev_count = cur_count;
                    cur_count = 0;
                } else if st_sec > now_sec {
                    // we're behind the stored value
                    now_ms = 0;
                    now_sec = st_sec;
                } else {
                    // st_sec < now_sec - 1
                    prev_count = 0;
                    cur_count = 0;
                }
            }

            let windowed_count_est = cur_count + (prev_count * (1000 - (now_ms % 1000))) / 1000;
            if windowed_count_est >= self.max_per_second {
                return false;
            }

            cur_count += 1;
            let new_counts = join_u64(now_sec, prev_count, cur_count);
            match self.counts.compare_exchange_weak(
                prev,
                new_counts,
                Ordering::Relaxed,
                Ordering::Relaxed,
            ) {
                Ok(_) => return true,
                Err(next_prev) => prev = next_prev,
            }
        }
    }
}

fn monotonic_time_millis() -> i64 {
    let mut ts: MaybeUninit<libc::timespec> = MaybeUninit::uninit();
    unsafe {
        let res = libc::clock_gettime(libc::CLOCK_MONOTONIC, ts.as_mut_ptr());
        if res != 0 {
            panic!("clock_gettime failed: {}", std::io::Error::last_os_error());
        }
    }
    let ts = unsafe { ts.assume_init() };
    (ts.tv_sec * 1_000) + (ts.tv_nsec / 1_000_000)
}

fn split_u64(c: u64) -> (u16, u32, u32) {
    let cur_sec = (c >> 48) as u16;
    let prev_sec_count = ((c >> 24) & 0xFF_FFFF) as u32;
    let cur_sec_count = (c & 0xFF_FFFF) as u32;
    (cur_sec, prev_sec_count, cur_sec_count)
}

fn join_u64(cur_sec: u16, prev_sec_count: u32, cur_sec_count: u32) -> u64 {
    ((cur_sec as u64) << 48)
        | (((prev_sec_count & 0xFF_FFFF) as u64) << 24)
        | ((cur_sec_count & 0xFF_FFFF) as u64)
}
