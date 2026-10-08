//! Linux liveness backend.
//!
//! Participant identities are kernel thread IDs, not thread-group IDs. All
//! users of a mapping must share a PID and time namespace and a matching
//! `/proc`. A mapping must not be reused across boots.

use core::ffi::c_long;

use crate::error::Error;
use crate::pid::GetPid;

/// `MEMBARRIER_CMD_GLOBAL` from `<linux/membarrier.h>`.
const MEMBARRIER_CMD_GLOBAL: libc::c_int = 1;

/// Kernel TID identities, `/proc/<tid>/stat` start times and liveness, with
/// a global `membarrier` as the synchronisation with a dead thread.
#[derive(Clone, Copy, Debug, Default)]
pub struct LinuxGetPid;

impl GetPid for LinuxGetPid {
    /// The calling thread's TID (not cached: it would be wrong after fork).
    #[inline]
    fn get_pid() -> u32 {
        // SAFETY: gettid has no preconditions.
        let tid = unsafe { libc::syscall(libc::SYS_gettid) };
        // Zero and the sign bit are reserved by the participant state
        // encoding.
        if tid <= 0 || tid > i32::MAX as c_long {
            crate::assert::fatal("gettid returned an unusable thread id");
        }
        tid as u32
    }

    /// `/proc/<tid>/stat` starttime, in clock ticks. Zero means unavailable;
    /// liveness checks then fall back to the TID alone (conservatively).
    fn get_start_time() -> u64 {
        read_stat(Self::get_pid()).map_or(0, |s| s.start_time)
    }

    fn is_live(tid: u32) -> Result<bool, Error> {
        Self::is_live_since(tid, 0)
    }

    /// `Ok(false)` requires proof of death and a successful global
    /// membarrier. Unknown liveness means possibly alive; synchronisation
    /// failures are errors.
    fn is_live_since(tid: u32, start_time: u64) -> Result<bool, Error> {
        if tid == 0 || tid > i32::MAX as u32 {
            return Ok(true);
        }

        let dead = match read_stat(tid) {
            Some(current) => {
                matches!(current.state, b'Z' | b'X' | b'x')
                    || (start_time != 0 && current.start_time != start_time)
            }
            None => {
                // Missing /proc entries can also mean hidepid or a missing
                // mount. Probe the TID, not its process. Signal zero
                // delivers no signal; TID reuse can only make this fallback
                // conservatively report live.
                // SAFETY: tkill with signal 0 has no side effects.
                let result = unsafe { libc::syscall(libc::SYS_tkill, tid as libc::c_int, 0) };
                result == -1 && errno() == libc::ESRCH
            }
        };

        if !dead {
            return Ok(true);
        }
        synchronize_death()?;
        Ok(false)
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
struct TaskStat {
    tid: u32,
    state: u8,
    start_time: u64,
}

#[inline]
fn errno() -> libc::c_int {
    // SAFETY: the thread's errno location is always valid.
    unsafe { *libc::__errno_location() }
}

/// Reads and parses `/proc/<tid>/stat`; `None` on any failure, an
/// incomplete read, or a mismatched TID.
fn read_stat(tid: u32) -> Option<TaskStat> {
    // "/proc/%u/stat" without core::fmt.
    let mut filename = [0u8; 64];
    let mut digits = [0u8; 10];
    let mut start = digits.len();
    let mut v = tid;
    loop {
        start -= 1;
        digits[start] = b'0' + (v % 10) as u8;
        v /= 10;
        if v == 0 {
            break;
        }
    }
    let digits = &digits[start..];
    let stat = 6 + digits.len();
    filename[..6].copy_from_slice(b"/proc/");
    filename[6..stat].copy_from_slice(digits);
    filename[stat..stat + 5].copy_from_slice(b"/stat");
    // filename[stat + 5] is the terminating NUL.

    let fd = loop {
        // SAFETY: filename is NUL-terminated.
        let fd = unsafe { libc::open(filename.as_ptr().cast(), libc::O_RDONLY | libc::O_CLOEXEC) };
        if !(fd == -1 && errno() == libc::EINTR) {
            break fd;
        }
    };
    if fd == -1 {
        return None;
    }

    let mut buffer = [0u8; 4096];
    let mut used = 0usize;
    let mut complete = false;
    while used < buffer.len() {
        // SAFETY: the destination range is within buffer.
        let amount = unsafe {
            libc::read(
                fd,
                buffer.as_mut_ptr().add(used).cast(),
                buffer.len() - used,
            )
        };
        if amount == -1 && errno() == libc::EINTR {
            continue;
        }
        if amount <= 0 {
            complete = amount == 0;
            break;
        }
        used += amount as usize;
    }
    // On Linux, retrying close after EINTR could close a reused descriptor.
    // SAFETY: fd is open and owned here.
    unsafe { libc::close(fd) };
    if !complete {
        return None;
    }
    parse_stat(&buffer[..used]).filter(|s| s.tid == tid)
}

/// Parses the tid, state (field 3) and starttime (field 22) of a
/// `/proc/<tid>/stat` line.
fn parse_stat(contents: &[u8]) -> Option<TaskStat> {
    const DELIMITERS: &[u8] = b" \t\n";
    let first_space = contents.iter().position(|&c| c == b' ')?;
    let tid = parse_decimal(&contents[..first_space])?;
    let tid = u32::try_from(tid).ok()?;
    let mut contents = &contents[first_space + 1..];
    // comm is parenthesised but unescaped: only the last ')' terminates it.
    let end_comm = contents.iter().rposition(|&c| c == b')');
    if contents.first() != Some(&b'(') {
        return None;
    }
    contents = &contents[end_comm? + 1..];
    let mut state = 0u8;
    for field in 3..=22 {
        let begin = contents.iter().position(|c| !DELIMITERS.contains(c))?;
        contents = &contents[begin..];
        // Require a delimiter so a truncated starttime is never accepted.
        let end = contents.iter().position(|c| DELIMITERS.contains(c))?;
        if field == 3 {
            if end != 1 {
                return None;
            }
            state = contents[0];
        } else if field == 22 {
            let start_time = parse_decimal(&contents[..end])?;
            return Some(TaskStat {
                tid,
                state,
                start_time,
            });
        }
        contents = &contents[end..];
    }
    None
}

/// An unsigned decimal consuming all of `digits`: digits only, at least one,
/// no sign, no overflow.
fn parse_decimal(digits: &[u8]) -> Option<u64> {
    if digits.is_empty() {
        return None;
    }
    let mut value: u64 = 0;
    for &c in digits {
        if !c.is_ascii_digit() {
            return None;
        }
        value = value.checked_mul(10)?.checked_add((c - b'0') as u64)?;
    }
    Some(value)
}

/// /proc and tkill establish identity/death, not the cache's memory-order
/// contract. GLOBAL supplies a Linux system-wide ordering boundary before
/// recovery reads the departed task's final shared-memory writes. Unlike
/// PRIVATE or GLOBAL_EXPEDITED it needs no cooperation/registration from
/// the departed process. This is a platform guarantee, not release/acquire
/// synchronisation supplied by an ordinary syscall.
fn synchronize_death() -> Result<(), Error> {
    let result = loop {
        // SAFETY: membarrier has no memory-safety preconditions.
        let result = unsafe { libc::syscall(libc::SYS_membarrier, MEMBARRIER_CMD_GLOBAL, 0, 0) };
        if !(result == -1 && errno() == libc::EINTR) {
            break result;
        }
    };
    if result == 0 {
        return Ok(());
    }
    // Unsupported kernels/nohz_full cannot satisfy this backend's contract.
    // Other failures (including sandbox denial) are reported as system I/O
    // errors. Neither case is a liveness answer that callers should retry.
    let e = errno();
    Err(if e == libc::ENOSYS || e == libc::EINVAL {
        Error::Unsupported
    } else {
        Error::IoError
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_stat_lines() {
        let line = b"1234 (a b) c)) S 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 987654 20 21\n";
        assert_eq!(
            parse_stat(line),
            Some(TaskStat {
                tid: 1234,
                state: b'S',
                start_time: 987654
            })
        );
        // Truncated starttime (no delimiter after it).
        assert_eq!(
            parse_stat(b"1 (x) S 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 99"),
            None
        );
        assert_eq!(
            parse_stat(b"+1 (x) S 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 9 "),
            None
        );
        assert_eq!(
            parse_stat(b"1 x) S 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 9 "),
            None
        );
        assert_eq!(
            parse_stat(b"1 (x) SS 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 9 "),
            None
        );
    }

    #[test]
    fn own_thread_is_live() {
        let tid = LinuxGetPid::get_pid();
        let start = LinuxGetPid::get_start_time();
        assert_ne!(start, 0);
        assert_eq!(read_stat(tid).map(|s| s.start_time), Some(start));
        assert_eq!(LinuxGetPid::is_live_since(tid, start), Ok(true));
    }
}
