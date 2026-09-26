//! The raw-clone signal worker owns no libc or Rust thread state. `prepare` and `drop` run on
//! ordinary initialized threads; only `run` may execute in the worker. `run` uses ordinary Rust
//! control flow, but every operation in its generated call graph must reduce to local memory
//! access or a direct Linux system call. Changes to it therefore require a disassembly audit in
//! both optimized and debug builds.

use core::arch::asm;
use datadog_sidecar::service::blocking::SidecarTransport;
use datadog_sidecar::service::sidecar_interface::{SidecarFlushOptions, SidecarInterfaceRequest};
use libdd_common_ffi::{Error, MaybeError};
use libdd_ipc::SeqpacketConn;
use std::mem::{offset_of, size_of};

/// Prepared, independent, sessionless connection. Opaque to C; immutable after publication.
/// Only normal initialized threads may construct or destroy this object.
pub struct SignalFlush {
    // Keep the raw view free of owning Rust values: the worker only copies these four fields.
    raw: RawSignalFlush,
    // These owners make the fd and request pointer in `raw` valid until normal-context drop.
    _connection: SeqpacketConn,
    _request: Box<[u8]>,
}

#[repr(C)]
struct RawSignalFlush {
    fd: i32,
    owner_pid: i32,
    request: *const u8,
    request_len: usize,
}

// Direct syscalls use the 64-bit Linux kernel ABI, not libc's platform types.
#[repr(C)]
struct KernelTimespec {
    seconds: i64,
    nanoseconds: i64,
}

// Match the pollfd layout consumed directly by the kernel.
#[repr(C)]
struct KernelPollFd {
    fd: i32,
    events: i16,
    revents: i16,
}

/// Connect independently to the template's exact listener and prepare one Flush request.
/// The template is borrowed only during this call: neither it nor its fd is retained.
/// The returned object must outlive the raw worker and may only be dropped normally.
#[no_mangle]
pub unsafe extern "C" fn datadog_sidecar_prepare_signal_flush(
    template: *mut SidecarTransport,
    output: *mut *mut SignalFlush,
) -> MaybeError {
    // Everything that may allocate, lock, inspect libc state, or use the sidecar codec belongs in
    // this function. None of those operations can be deferred to the raw worker.
    let result = (|| {
        let output = output
            .as_mut()
            .ok_or_else(|| anyhow::anyhow!("signal flush output is null"))?;
        // Never leave the caller holding an old value when a later preparation step fails.
        *output = std::ptr::null_mut();
        let template = template
            .as_mut()
            .ok_or_else(|| anyhow::anyhow!("signal flush template is null"))?;
        // This is a new socket, not a dup of the template fd. A dup would keep the template's
        // connection alive and interfere with the sidecar's disconnect detection.
        let connection = connect_to_template(template)?;
        // Encoding now leaves `run` with one immutable packet and no serializer call graph.
        let request = libdd_ipc::codec::encode(&SidecarInterfaceRequest::Flush {
            // Preserve the previous forced-shutdown behavior: traces and stats are the only data
            // whose delivery this signal path delays termination to await.
            options: SidecarFlushOptions {
                traces_and_stats: true,
                flag_evaluations: false,
                telemetry: false,
            },
        })
        .into_boxed_slice();

        *output = Box::into_raw(Box::new(SignalFlush {
            raw: RawSignalFlush {
                fd: connection.as_raw_fd(),
                // A copied object must never send on the parent's connection after fork.
                owner_pid: libc::getpid(),
                request: request.as_ptr(),
                request_len: request.len(),
            },
            _connection: connection,
            _request: request,
        }));
        Ok::<_, anyhow::Error>(())
    })();
    match result {
        Ok(()) => MaybeError::None,
        Err(error) => MaybeError::Some(Error::from(format!("{error:#}"))),
    }
}

/// Destroy an unpublished object, or one whose raw worker has exited. Normal context only.
#[no_mangle]
pub unsafe extern "C" fn datadog_sidecar_signal_flush_drop(flush: *mut SignalFlush) {
    // An unpublished object has no worker borrower. For a published object, C joins the worker
    // through clear_child_tid before calling this, so neither owner can be destroyed during `run`.
    if !flush.is_null() {
        drop(Box::from_raw(flush));
    }
}

/// Execute one bounded exchange without calling libc, using TLS, allocating, or unwinding.
/// If `terminate_process` is true, terminate the process after the flush completes, fails, or
/// times out. Otherwise, return zero for the one-byte zero ACK or a negative Linux error number.
/// The caller must keep the object alive and guarantee exclusive, one-shot use of its socket.
#[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
#[no_mangle]
#[inline(never)]
// An `extern "C"` definition gets a `panic_cannot_unwind` path in panic=unwind debug builds.
// `C-unwind` avoids that generated call. The body is deliberately written so it cannot unwind.
pub unsafe extern "C-unwind" fn datadog_sidecar_signal_flush_run(
    flush: *const SignalFlush,
    terminate_process: bool,
) -> i32 {
    let result = signal_flush_exchange(flush);
    if terminate_process {
        // The raw clone has no usable libc thread state. Terminate through an inline syscall
        // rather than returning to C or calling libc's `_exit`/`exit` machinery.
        signal_safe_exit_group();
    }
    result
}

#[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
#[inline(never)]
unsafe fn signal_flush_exchange(flush: *const SignalFlush) -> i32 {
    // Raw Linux syscalls return `-errno` directly. They do not set libc's thread-local `errno`.
    const EINTR: isize = 4;
    const EAGAIN: isize = 11;
    const CLOCK_MONOTONIC: usize = 1;
    const MSG_DONTWAIT: usize = 0x40;
    const MSG_NOSIGNAL: usize = 0x4000;
    const MSG_TRUNC: usize = 0x20;
    const POLLIN: i16 = 1;
    const POLLOUT: i16 = 4;

    // Syscall numbers are architecture-specific, so keep them beside the audited inline syscall
    // rather than routing through libc wrappers.
    #[cfg(target_arch = "aarch64")]
    const SYS_GETPID: usize = 172;
    #[cfg(target_arch = "aarch64")]
    const SYS_SENDTO: usize = 206;
    #[cfg(target_arch = "aarch64")]
    const SYS_RECVFROM: usize = 207;
    #[cfg(target_arch = "aarch64")]
    const SYS_CLOCK_GETTIME: usize = 113;
    #[cfg(target_arch = "aarch64")]
    const SYS_PPOLL: usize = 73;

    #[cfg(target_arch = "x86_64")]
    const SYS_GETPID: usize = 39;
    #[cfg(target_arch = "x86_64")]
    const SYS_SENDTO: usize = 44;
    #[cfg(target_arch = "x86_64")]
    const SYS_RECVFROM: usize = 45;
    #[cfg(target_arch = "x86_64")]
    const SYS_CLOCK_GETTIME: usize = 228;
    #[cfg(target_arch = "x86_64")]
    const SYS_PPOLL: usize = 271;

    let (fd, owner_pid, request, request_len) = signal_safe_load(flush);
    // `fork()` copies the object and fd but changes the process identity. Reject that copy even if
    // a caller reaches this API before the C-side post-fork reset.
    if signal_safe_syscall6(SYS_GETPID, 0, 0, 0, 0, 0, 0) != owner_pid as isize {
        return -libc::ECHILD;
    }

    let mut now = KernelTimespec {
        seconds: 0,
        nanoseconds: 0,
    };
    let result = signal_safe_syscall6(
        SYS_CLOCK_GETTIME,
        CLOCK_MONOTONIC,
        (&raw mut now).cast::<u8>() as usize,
        0,
        0,
        0,
        0,
    );
    if result < 0 {
        return result as i32;
    }
    // Use one absolute deadline for send, backpressure, and ACK. Restarting a relative timeout
    // after EINTR or EAGAIN could otherwise keep process termination alive indefinitely.
    // Wrapping arithmetic is intentional: debug overflow checks would introduce panic paths.
    let deadline_seconds = now.seconds.wrapping_add(10);
    let deadline_nanoseconds = now.nanoseconds;
    // `receive` selects the protocol phase. `poll` records that the last nonblocking operation
    // returned EAGAIN and must wait for readiness before retrying.
    let mut receive = false;
    let mut poll = false;

    loop {
        let result = signal_safe_syscall6(
            SYS_CLOCK_GETTIME,
            CLOCK_MONOTONIC,
            (&raw mut now).cast::<u8>() as usize,
            0,
            0,
            0,
            0,
        );
        if result < 0 {
            return result as i32;
        }
        let mut remaining_seconds = deadline_seconds.wrapping_sub(now.seconds);
        let mut remaining_nanoseconds = deadline_nanoseconds.wrapping_sub(now.nanoseconds);
        if remaining_nanoseconds < 0 {
            remaining_nanoseconds = remaining_nanoseconds.wrapping_add(1_000_000_000);
            remaining_seconds = remaining_seconds.wrapping_sub(1);
        }
        if remaining_seconds < 0 || (remaining_seconds == 0 && remaining_nanoseconds == 0) {
            return -libc::ETIMEDOUT;
        }
        let mut remaining = KernelTimespec {
            seconds: remaining_seconds,
            nanoseconds: remaining_nanoseconds,
        };

        if poll {
            let mut pollfd = KernelPollFd {
                fd,
                events: if receive { POLLIN } else { POLLOUT },
                revents: 0,
            };
            let result = signal_safe_syscall6(
                SYS_PPOLL,
                (&raw mut pollfd).cast::<u8>() as usize,
                1,
                (&raw mut remaining).cast::<u8>() as usize,
                0,
                // The kernel's 64-bit signal-set size is eight bytes. No mask is supplied, but
                // passing the ABI size keeps this a valid direct ppoll syscall on both targets.
                8,
                0,
            );
            if result > 0 || result == -EINTR {
                poll = false;
                continue;
            }
            if result == 0 {
                return -libc::ETIMEDOUT;
            }
            return result as i32;
        }

        let result = if receive {
            let mut ack = 1u8;
            let result = signal_safe_syscall6(
                SYS_RECVFROM,
                fd as usize,
                (&raw mut ack) as usize,
                1,
                // MSG_TRUNC makes a packet larger than the one-byte buffer report its full size,
                // so only an exact one-byte zero ACK can be accepted.
                MSG_DONTWAIT | MSG_TRUNC,
                0,
                0,
            );
            if result == 1 {
                return if ack == 0 { 0 } else { -libc::EPROTO };
            }
            result
        } else {
            let result = signal_safe_syscall6(
                SYS_SENDTO,
                fd as usize,
                request as usize,
                request_len,
                // Nonblocking I/O lets the single ppoll deadline bound backpressure. MSG_NOSIGNAL
                // prevents a closed sidecar socket from delivering SIGPIPE to the raw worker.
                MSG_DONTWAIT | MSG_NOSIGNAL,
                0,
                0,
            );
            // SOCK_SEQPACKET preserves message boundaries: a positive short send is a protocol
            // failure rather than progress that can be resumed with a pointer offset.
            if result == request_len as isize {
                receive = true;
                continue;
            }
            result
        };

        if result == -EINTR {
            continue;
        }
        if result == -EAGAIN {
            // Poll only after the kernel reports backpressure; a readiness wakeup retries the
            // original send or receive operation and recomputes the remaining absolute deadline.
            poll = true;
            continue;
        }
        if result < 0 {
            return result as i32;
        }
        // Any other positive result is an impossible short packet/send for this protocol.
        return -libc::EPROTO;
    }
}

#[cfg(target_arch = "x86_64")]
#[inline(always)]
unsafe fn signal_safe_exit_group() -> ! {
    // SYS_exit_group(0). The syscall cannot return, so no register clobbers remain live.
    asm!(
        "syscall",
        in("rax") 231usize,
        in("rdi") 0usize,
        options(noreturn, nostack),
    );
}

#[cfg(target_arch = "x86_64")]
#[inline(always)]
unsafe fn signal_safe_syscall6(
    number: usize,
    arg1: usize,
    arg2: usize,
    arg3: usize,
    arg4: usize,
    arg5: usize,
    arg6: usize,
) -> isize {
    let result: isize;
    // Linux x86_64 uses r10, not the C ABI's rcx, for argument four. `syscall` itself clobbers rcx
    // and r11; declaring both prevents the surrounding Rust from keeping live values there.
    asm!(
        "syscall",
        inlateout("rax") number as isize => result,
        in("rdi") arg1,
        in("rsi") arg2,
        in("rdx") arg3,
        in("r10") arg4,
        in("r8") arg5,
        in("r9") arg6,
        lateout("rcx") _,
        lateout("r11") _,
        options(nostack, preserves_flags),
    );
    result
}

#[cfg(target_arch = "x86_64")]
#[inline(always)]
unsafe fn signal_safe_load(flush: *const SignalFlush) -> (i32, i32, *const u8, usize) {
    let fd: i32;
    let owner_pid: i32;
    let request: *const u8;
    let request_len: usize;
    // Do not replace this with `&(*flush).raw`. In panic=unwind debug builds that reference
    // construction emitted null/alignment checks which call Rust panic handlers. Those handlers
    // may allocate, use TLS, or otherwise depend on runtime state absent from the raw clone.
    // These fixed-offset loads were audited to compile to four `mov` instructions and no calls.
    asm!(
        "movl {fd_offset}({base}), {fd:e}",
        "movl {pid_offset}({base}), {owner_pid:e}",
        "movq {request_offset}({base}), {request}",
        "movq {length_offset}({base}), {request_len}",
        base = in(reg) flush,
        fd = out(reg) fd,
        owner_pid = out(reg) owner_pid,
        request = out(reg) request,
        request_len = out(reg) request_len,
        fd_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, fd),
        pid_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, owner_pid),
        request_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, request),
        length_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, request_len),
        options(att_syntax, nostack, readonly, preserves_flags),
    );
    (fd, owner_pid, request, request_len)
}

#[cfg(target_arch = "aarch64")]
#[inline(always)]
unsafe fn signal_safe_exit_group() -> ! {
    // SYS_exit_group(0). The syscall cannot return, so no register clobbers remain live.
    asm!(
        "svc #0",
        in("x8") 94usize,
        in("x0") 0usize,
        options(noreturn, nostack),
    );
}

#[cfg(target_arch = "aarch64")]
#[inline(always)]
unsafe fn signal_safe_syscall6(
    number: usize,
    arg1: usize,
    arg2: usize,
    arg3: usize,
    arg4: usize,
    arg5: usize,
    arg6: usize,
) -> isize {
    let result: isize;
    // Linux AArch64 takes the syscall number in x8, arguments in x0..x5, and returns in x0.
    // Listing every register explicitly keeps the compiler from generating a helper call.
    asm!(
        "svc #0",
        in("x8") number,
        inlateout("x0") arg1 as isize => result,
        in("x1") arg2,
        in("x2") arg3,
        in("x3") arg4,
        in("x4") arg5,
        in("x5") arg6,
        options(nostack),
    );
    result
}

#[cfg(target_arch = "aarch64")]
#[inline(always)]
unsafe fn signal_safe_load(flush: *const SignalFlush) -> (i32, i32, *const u8, usize) {
    let fd: i32;
    let owner_pid: i32;
    let request: *const u8;
    let request_len: usize;
    // As on x86_64, an ordinary Rust reference introduced panic-handler calls in debug builds.
    // These fixed-offset loads were audited to compile to four `ldr` instructions and no calls.
    asm!(
        "ldr {fd:w}, [{base}, #{fd_offset}]",
        "ldr {owner_pid:w}, [{base}, #{pid_offset}]",
        "ldr {request}, [{base}, #{request_offset}]",
        "ldr {request_len}, [{base}, #{length_offset}]",
        base = in(reg) flush,
        fd = out(reg) fd,
        owner_pid = out(reg) owner_pid,
        request = out(reg) request,
        request_len = out(reg) request_len,
        fd_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, fd),
        pid_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, owner_pid),
        request_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, request),
        length_offset = const offset_of!(SignalFlush, raw) + offset_of!(RawSignalFlush, request_len),
        options(nostack, readonly, preserves_flags),
    );
    (fd, owner_pid, request, request_len)
}

// The clone trampoline also rejects unsupported architectures. Never substitute libc here.
/// cbindgen:ignore
#[cfg(not(any(target_arch = "x86_64", target_arch = "aarch64")))]
#[no_mangle]
pub unsafe extern "C" fn datadog_sidecar_signal_flush_run(
    _flush: *const SignalFlush,
    _terminate_process: bool,
) -> i32 {
    -libc::ENOTSUP
}

fn connect_to_template(template: &mut SidecarTransport) -> anyhow::Result<SeqpacketConn> {
    // SidecarTransport intentionally hides its endpoint. getpeername recovers the connected
    // listener address without borrowing or retaining the template transport itself.
    let mut address: libc::sockaddr_un = unsafe { std::mem::zeroed() };
    let mut length = size_of::<libc::sockaddr_un>() as libc::socklen_t;
    if unsafe {
        libc::getpeername(
            template.as_raw_fd(),
            (&mut address as *mut libc::sockaddr_un).cast(),
            &mut length,
        )
    } != 0
    {
        return Err(std::io::Error::last_os_error().into());
    }
    let start = offset_of!(libc::sockaddr_un, sun_path);
    let length = length as usize;
    anyhow::ensure!(
        address.sun_family == libc::AF_UNIX as libc::sa_family_t
            && length > start + 1
            && length <= size_of::<libc::sockaddr_un>()
            && address.sun_path[0] == 0,
        "signal flush template does not name an abstract Unix listener"
    );
    // Linux abstract names are length-delimited and may contain embedded NULs. Use the sockaddr
    // length returned by the kernel rather than treating sun_path as a C string.
    let name = unsafe {
        std::slice::from_raw_parts(address.sun_path.as_ptr().add(1).cast(), length - start - 1)
    };
    Ok(SeqpacketConn::connect_abstract(name)?)
}

#[cfg(test)]
mod tests {
    use super::*;
    use libdd_ipc::SeqpacketListener;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::time::{Duration, Instant};

    fn pair() -> (SignalFlush, SeqpacketConn) {
        let (connection, peer) = SeqpacketConn::socketpair().unwrap();
        let request = vec![42u8].into_boxed_slice();
        let flush = SignalFlush {
            raw: RawSignalFlush {
                fd: connection.as_raw_fd(),
                owner_pid: unsafe { libc::getpid() },
                request: request.as_ptr(),
                request_len: request.len(),
            },
            _connection: connection,
            _request: request,
        };
        (flush, peer)
    }

    fn send(peer: &SeqpacketConn, bytes: &[u8]) {
        assert_eq!(
            unsafe { libc::send(peer.as_raw_fd(), bytes.as_ptr().cast(), bytes.len(), 0) },
            bytes.len() as isize
        );
    }

    #[test]
    fn accepts_only_an_exact_zero_ack_packet() {
        for (ack, expected) in [
            (&[0][..], 0),
            (&[0, 0][..], -libc::EPROTO),
            (&[1][..], -libc::EPROTO),
        ] {
            let (flush, peer) = pair();
            send(&peer, ack);
            assert_eq!(
                unsafe { datadog_sidecar_signal_flush_run(&flush, false) },
                expected
            );
        }
    }

    #[test]
    fn waits_for_the_ack_after_sending() {
        let (flush, peer) = pair();
        let server = std::thread::spawn(move || {
            let mut pollfd = libc::pollfd {
                fd: peer.as_raw_fd(),
                events: libc::POLLIN,
                revents: 0,
            };
            assert_eq!(unsafe { libc::poll(&mut pollfd, 1, 1000) }, 1);
            let mut request = 0u8;
            assert_eq!(
                unsafe { libc::recv(peer.as_raw_fd(), (&mut request as *mut u8).cast(), 1, 0) },
                1
            );
            assert_eq!(request, 42);
            std::thread::sleep(Duration::from_millis(20));
            send(&peer, &[0]);
        });
        assert_eq!(
            unsafe { datadog_sidecar_signal_flush_run(&flush, false) },
            0
        );
        server.join().unwrap();
    }

    #[test]
    fn retries_send_after_backpressure() {
        let (flush, peer) = pair();
        let filler = [0u8; 512];
        loop {
            let sent = unsafe {
                libc::send(
                    flush.raw.fd,
                    filler.as_ptr().cast(),
                    filler.len(),
                    libc::MSG_DONTWAIT | libc::MSG_NOSIGNAL,
                )
            };
            if sent < 0 {
                assert_eq!(
                    std::io::Error::last_os_error().raw_os_error(),
                    Some(libc::EAGAIN)
                );
                break;
            }
        }
        let server = std::thread::spawn(move || {
            std::thread::sleep(Duration::from_millis(20));
            let mut bytes = [0u8; 512];
            loop {
                let mut pollfd = libc::pollfd {
                    fd: peer.as_raw_fd(),
                    events: libc::POLLIN,
                    revents: 0,
                };
                assert_eq!(unsafe { libc::poll(&mut pollfd, 1, 1000) }, 1);
                let received = unsafe {
                    libc::recv(peer.as_raw_fd(), bytes.as_mut_ptr().cast(), bytes.len(), 0)
                };
                assert!(received > 0);
                if received == 1 && bytes[0] == 42 {
                    break;
                }
                assert_eq!(received, bytes.len() as isize);
            }
            send(&peer, &[0]);
        });
        assert_eq!(
            unsafe { datadog_sidecar_signal_flush_run(&flush, false) },
            0
        );
        server.join().unwrap();
    }

    #[test]
    fn rejects_a_closed_peer_without_sigpipe() {
        let (flush, peer) = pair();
        drop(peer);
        assert!(unsafe { datadog_sidecar_signal_flush_run(&flush, false) } < 0);
    }

    #[test]
    fn refuses_inherited_objects() {
        let (mut flush, _peer) = pair();
        flush.raw.owner_pid += 1;
        assert_eq!(
            unsafe { datadog_sidecar_signal_flush_run(&flush, false) },
            -libc::ECHILD
        );
    }

    #[test]
    fn missing_ack_has_one_ten_second_deadline() {
        let (flush, _peer) = pair();
        let start = Instant::now();
        assert_eq!(
            unsafe { datadog_sidecar_signal_flush_run(&flush, false) },
            -libc::ETIMEDOUT
        );
        assert!(start.elapsed() >= Duration::from_secs(9));
        assert!(start.elapsed() < Duration::from_secs(15));
    }

    #[test]
    fn preparation_creates_an_independent_connection_to_the_template_listener() {
        static NEXT: AtomicUsize = AtomicUsize::new(0);
        let name = format!(
            "ddtrace-signal-flush-{}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        );
        let listener = SeqpacketListener::bind_abstract(name.as_bytes()).unwrap();
        let mut template =
            SidecarTransport::from(SeqpacketConn::connect_abstract(name.as_bytes()).unwrap());
        let template_peer = listener.try_accept().unwrap();
        let mut output = std::ptr::null_mut();
        assert!(matches!(
            unsafe { datadog_sidecar_prepare_signal_flush(&mut template, &mut output) },
            MaybeError::None
        ));
        let flush = unsafe { Box::from_raw(output) };
        let signal_peer = listener.try_accept().unwrap();
        assert_ne!(flush.raw.fd, template.as_raw_fd());

        // Closing the normal connection must produce EOF despite the signal connection.
        drop(template);
        let mut bytes = [0u8; 256];
        assert_eq!(
            unsafe {
                libc::recv(
                    template_peer.as_raw_fd(),
                    bytes.as_mut_ptr().cast(),
                    bytes.len(),
                    0,
                )
            },
            0
        );
        send(&signal_peer, &[0]);
        assert_eq!(
            unsafe { datadog_sidecar_signal_flush_run(&*flush, false) },
            0
        );
        let received = unsafe {
            libc::recv(
                signal_peer.as_raw_fd(),
                bytes.as_mut_ptr().cast(),
                bytes.len(),
                0,
            )
        };
        assert_eq!(received as usize, flush._request.len());
        assert_eq!(&bytes[..received as usize], &*flush._request);
        drop(flush);
        assert_eq!(
            unsafe {
                libc::recv(
                    signal_peer.as_raw_fd(),
                    bytes.as_mut_ptr().cast(),
                    bytes.len(),
                    0,
                )
            },
            0
        );
    }
}
