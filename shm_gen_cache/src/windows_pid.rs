//! Windows liveness backend.
//!
//! Participant identities are thread IDs, which are system-wide. All users
//! of a mapping must run in the same server silo (no container boundary
//! between them) and be able to open each other's threads with
//! `SYNCHRONIZE | THREAD_QUERY_LIMITED_INFORMATION` (normally: run as the
//! same user); dead participants whose threads cannot be opened are
//! conservatively never reaped. A mapping must not be reused across boots.
//!
//! Observing a thread's death synchronises with its final shared-memory
//! writes without a barrier of our own. A signalled thread object does so
//! by documentation: wait functions and the signalling of synchronisation
//! objects are barriers (`JoinHandle::join` relies on the same). A deleted
//! or replaced thread object (its thread ID freed, perhaps reused) has no
//! documented ordering, and Windows has no analogue of Linux's global
//! membarrier (FlushProcessWriteBuffers only reaches this process's
//! threads). The ordering comes from the kernel instead; checked in the x64
//! ntoskrnl of Windows Server 2019 (10.0.17763):
//! - the thread ID is freed only by the thread object's delete routine
//!   (`PspThreadDelete` calls `ExDestroyHandle(PspCidTable, ..)`);
//! - the exiting thread's own reference is dropped by `PspReaper`, after
//!   `KeDeleteThread` has waited (`KiWaitForContextSwap`) for
//!   `KTHREAD.Running` to be cleared, which `SwapContext` does once the
//!   thread is switched out for the last time;
//! - `OpenThread` looks the ID up with locked instructions
//!   (`PspReferenceCidTableEntry`).
//!
//! So the thread's last stores precede `Running = 0`, which precedes the
//! freeing of the ID that `OpenThread` observed; on x86-64 (a single global
//! store order, loads not reordered with older loads) those stores are
//! visible to our later loads. aarch64 Windows has not been checked.

use windows_sys::Win32::Foundation::{
    CloseHandle, ERROR_INVALID_PARAMETER, FILETIME, GetLastError, HANDLE, WAIT_OBJECT_0,
    WAIT_TIMEOUT,
};
use windows_sys::Win32::System::Threading::{
    GetCurrentThread, GetCurrentThreadId, GetThreadTimes, OpenThread,
    THREAD_QUERY_LIMITED_INFORMATION, THREAD_SYNCHRONIZE, WaitForSingleObject,
};

use crate::error::Error;
use crate::pid::GetPid;

/// Thread ID identities, creation-time start times and liveness through the
/// thread object: its signalled state, or its deletion.
#[derive(Clone, Copy, Debug, Default)]
pub struct WindowsGetPid;

impl GetPid for WindowsGetPid {
    /// The calling thread's ID.
    #[inline]
    fn get_pid() -> u32 {
        // SAFETY: GetCurrentThreadId has no preconditions.
        let tid = unsafe { GetCurrentThreadId() };
        // Zero and the sign bit are reserved by the participant state
        // encoding. Windows never uses zero; the upper bound is not
        // documented (thread IDs are CID table handles, far below 2^31).
        if tid == 0 || tid > i32::MAX as u32 {
            crate::assert::fatal("GetCurrentThreadId returned an unusable thread id");
        }
        tid
    }

    /// The thread's creation time (a FILETIME, in 100 ns units). Zero means
    /// unavailable; liveness checks then fall back to the TID alone
    /// (conservatively).
    fn get_start_time() -> u64 {
        // SAFETY: GetCurrentThread has no preconditions; the pseudo-handle
        // needs no closing.
        creation_time(unsafe { GetCurrentThread() }).unwrap_or(0)
    }

    fn is_live(tid: u32) -> Result<bool, Error> {
        Self::is_live_since(tid, 0)
    }

    /// `Ok(false)` requires the thread object to be signalled, deleted, or
    /// replaced by one with another creation time. Unknown liveness means
    /// possibly alive; a failed wait is an error.
    fn is_live_since(tid: u32, start_time: u64) -> Result<bool, Error> {
        if tid == 0 || tid > i32::MAX as u32 {
            return Ok(true);
        }

        // SAFETY: OpenThread has no memory-safety preconditions.
        let handle = unsafe {
            OpenThread(
                THREAD_SYNCHRONIZE | THREAD_QUERY_LIMITED_INFORMATION,
                0,
                tid,
            )
        };
        if handle == 0 {
            // A thread ID stays reserved until its thread object is deleted,
            // which follows termination (see the module docs); then
            // ERROR_INVALID_PARAMETER says no thread has this ID (it may be
            // a process's now). ACCESS_DENIED proves a thread exists (the
            // lookup precedes the access check) but not which incarnation;
            // other failures prove nothing.
            // SAFETY: GetLastError has no preconditions.
            return Ok(unsafe { GetLastError() } != ERROR_INVALID_PARAMETER);
        }
        let thread = ThreadHandle(handle);

        // SAFETY: the handle is open with SYNCHRONIZE.
        let live = match unsafe { WaitForSingleObject(thread.0, 0) } {
            // Terminated; if this is a reused TID, the incarnation asked
            // about ended even earlier.
            WAIT_OBJECT_0 => false,
            WAIT_TIMEOUT => {
                start_time == 0
                    || creation_time(thread.0).is_none_or(|current| current == start_time)
            }
            // The wait is the synchronisation primitive: its failure is not
            // a liveness answer.
            _ => return Err(Error::IoError),
        };
        Ok(live)
    }
}

/// Closes a handle from `OpenThread` on drop.
struct ThreadHandle(HANDLE);

impl Drop for ThreadHandle {
    fn drop(&mut self) {
        // SAFETY: an open handle owned here.
        unsafe { CloseHandle(self.0) };
    }
}

/// The creation time of `thread`; `None` on failure.
fn creation_time(thread: HANDLE) -> Option<u64> {
    let zero = FILETIME {
        dwLowDateTime: 0,
        dwHighDateTime: 0,
    };
    let (mut creation, mut exit, mut kernel, mut user) = (zero, zero, zero, zero);
    // SAFETY: four FILETIMEs to write; the handle has (limited) query access.
    let ok = unsafe { GetThreadTimes(thread, &mut creation, &mut exit, &mut kernel, &mut user) };
    let time = (creation.dwHighDateTime as u64) << 32 | creation.dwLowDateTime as u64;
    (ok != 0 && time != 0).then_some(time)
}

#[cfg(test)]
mod tests {
    use super::*;

    use std::io::{BufRead, BufReader, Read};
    use std::println;
    use std::process::{Child, ChildStdout, Command, Stdio};
    use std::string::String;
    use std::sync::mpsc;
    use std::thread;
    use std::time::{Duration, Instant};
    use std::vec::Vec;

    use windows_sys::Win32::System::Threading::INFINITE;

    /// Set in the environment of the child process of
    /// [`child_identity`]'s callers.
    const CHILD_ENV: &str = "SGC_WINDOWS_PID_CHILD";

    fn identity() -> (u32, u64) {
        (WindowsGetPid::get_pid(), WindowsGetPid::get_start_time())
    }

    fn open(tid: u32) -> ThreadHandle {
        // SAFETY: OpenThread has no memory-safety preconditions.
        let handle = unsafe {
            OpenThread(
                THREAD_SYNCHRONIZE | THREAD_QUERY_LIMITED_INFORMATION,
                0,
                tid,
            )
        };
        assert_ne!(handle, 0, "OpenThread({tid})");
        ThreadHandle(handle)
    }

    #[test]
    fn own_thread_is_live() {
        let (tid, start) = identity();
        assert!(tid != 0 && tid <= i32::MAX as u32);
        assert_ne!(start, 0);
        assert_eq!(creation_time(open(tid).0), Some(start));
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(true));
        assert_eq!(WindowsGetPid::is_live(tid), Ok(true));
    }

    #[test]
    fn start_time_mismatch_is_dead() {
        let (tid, start) = identity();
        assert_eq!(WindowsGetPid::is_live_since(tid, start + 1), Ok(false));
        assert_eq!(WindowsGetPid::is_live_since(tid, 0), Ok(true));
    }

    #[test]
    fn out_of_range_ids_are_live() {
        assert_eq!(WindowsGetPid::is_live(0), Ok(true));
        assert_eq!(WindowsGetPid::is_live(0x8000_0000), Ok(true));
    }

    #[test]
    fn running_thread_is_live() {
        let (report, identity_rx) = mpsc::channel();
        let (release, release_rx) = mpsc::channel::<()>();
        let worker = thread::spawn(move || {
            report.send(identity()).unwrap();
            let _ = release_rx.recv();
        });
        let (tid, start) = identity_rx.recv().unwrap();
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(true));
        assert_eq!(WindowsGetPid::is_live(tid), Ok(true));
        drop(release);
        worker.join().unwrap();
    }

    /// Joining closes the last handle, and the thread object is deleted
    /// once the exited thread's own reference goes too (asynchronously):
    /// OpenThread then fails with ERROR_INVALID_PARAMETER.
    #[test]
    fn joined_thread_is_dead() {
        let (tid, start) = thread::spawn(identity).join().unwrap();
        wait_for_deletion(tid);
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(false));
        assert_eq!(WindowsGetPid::is_live(tid), Ok(false));
    }

    /// Polls until no thread has ID `tid` (bounded).
    fn wait_for_deletion(tid: u32) {
        let deadline = Instant::now() + Duration::from_secs(10);
        loop {
            // SAFETY: OpenThread has no memory-safety preconditions.
            let handle = unsafe { OpenThread(THREAD_SYNCHRONIZE, 0, tid) };
            if handle == 0 {
                // SAFETY: GetLastError has no preconditions.
                assert_eq!(unsafe { GetLastError() }, ERROR_INVALID_PARAMETER);
                return;
            }
            drop(ThreadHandle(handle));
            assert!(Instant::now() < deadline, "thread {tid} was never deleted");
            thread::sleep(Duration::from_millis(1));
        }
    }

    /// A handle kept open keeps the terminated thread's object, now
    /// signalled.
    #[test]
    fn exited_thread_with_open_handle_is_dead() {
        let (report, identity_rx) = mpsc::channel();
        let (release, release_rx) = mpsc::channel::<()>();
        let worker = thread::spawn(move || {
            report.send(identity()).unwrap();
            let _ = release_rx.recv();
        });
        let (tid, start) = identity_rx.recv().unwrap();
        let kept = open(tid);
        drop(release);
        worker.join().unwrap();
        // SAFETY: the handle is open with SYNCHRONIZE.
        assert_eq!(unsafe { WaitForSingleObject(kept.0, 0) }, WAIT_OBJECT_0);
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(false));
        assert_eq!(WindowsGetPid::is_live(tid), Ok(false));
        drop(kept);
    }

    /// Runs [`child_identity`] in a child process; returns it, the rest of
    /// its output, and the identity of its thread, which waits for stdin to
    /// close.
    fn spawn_child() -> (Child, BufReader<ChildStdout>, u32, u64) {
        let mut child = Command::new(std::env::current_exe().unwrap())
            .args([
                "--exact",
                "windows_pid::tests::child_identity",
                "--ignored",
                "--nocapture",
                "--test-threads=1",
            ])
            .env(CHILD_ENV, "1")
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .spawn()
            .unwrap();
        let mut stdout = BufReader::new(child.stdout.take().unwrap());
        let mut line = String::new();
        while stdout.read_line(&mut line).unwrap() != 0 {
            // libtest prints the test's name on the same line.
            if let Some((_, identity)) = line.trim_end().split_once("sgc-child: ") {
                let (tid, start) = identity.split_once(' ').unwrap();
                let (tid, start) = (tid.parse().unwrap(), start.parse().unwrap());
                return (child, stdout, tid, start);
            }
            line.clear();
        }
        panic!(
            "the child exited without reporting its identity: {:?}",
            child.wait()
        );
    }

    /// The child side of the cross-process tests (a no-op elsewhere).
    #[test]
    #[ignore = "run by the cross-process tests in a child process"]
    fn child_identity() {
        if std::env::var_os(CHILD_ENV).is_none() {
            return;
        }
        let (tid, start) = identity();
        println!("sgc-child: {tid} {start}");
        let _ = std::io::stdin().read_to_end(&mut Vec::new());
    }

    #[test]
    fn dead_child_process_thread_is_dead() {
        let (mut child, mut stdout, tid, start) = spawn_child();
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(true));
        assert_eq!(WindowsGetPid::is_live(tid), Ok(true));
        drop(child.stdin.take());
        // Until it exits, so its last writes find the pipe open.
        stdout.read_to_end(&mut Vec::new()).unwrap();
        assert!(child.wait().unwrap().success());
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(false));
        assert_eq!(WindowsGetPid::is_live(tid), Ok(false));
    }

    /// TerminateProcess while the child's thread runs: a crash.
    #[test]
    fn killed_child_process_thread_is_dead() {
        let (mut child, _stdout, tid, start) = spawn_child();
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(true));
        let kept = open(tid);
        child.kill().unwrap();
        child.wait().unwrap();
        // The process is signalled before its last thread is.
        // SAFETY: the handle is open with SYNCHRONIZE.
        assert_eq!(
            unsafe { WaitForSingleObject(kept.0, INFINITE) },
            WAIT_OBJECT_0
        );
        assert_eq!(WindowsGetPid::is_live_since(tid, start), Ok(false));
        assert_eq!(WindowsGetPid::is_live(tid), Ok(false));
        drop(kept);
    }
}
