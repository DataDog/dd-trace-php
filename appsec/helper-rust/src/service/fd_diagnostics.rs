use std::{
    ffi::{CStr, OsStr},
    fmt::Write,
    io,
    os::unix::ffi::OsStrExt,
    path::Path,
    ptr::NonNull,
};

const MAX_FD_DETAILS_BYTES: usize = 128 * 1024;
const MAX_EXTENSION_BYTES: usize = 8;
const MAX_MEMORY_NAME_BYTES: usize = 16;
// escape_truncated reserves 3 bytes for the "..." marker.
const _: () = assert!(MAX_EXTENSION_BYTES >= 3 && MAX_MEMORY_NAME_BYTES >= 3);

pub(super) struct FdDiagnostics {
    directory: io::Result<Directory>,
}

impl FdDiagnostics {
    pub(super) fn new() -> Self {
        Self {
            directory: Directory::open(),
        }
    }

    pub(super) fn describe_error(&mut self, error: &anyhow::Error) -> String {
        if !error.chain().any(|cause| {
            cause.downcast_ref::<io::Error>().is_some_and(|error| {
                matches!(error.raw_os_error(), Some(libc::EMFILE | libc::ENFILE))
            })
        }) {
            return String::new();
        }

        let limits = describe_limits();
        match &mut self.directory {
            Ok(directory) => match directory.snapshot(MAX_FD_DETAILS_BYTES) {
                Ok((count, details, omitted)) => format!(
                    "; sidecar open_fds={count}; {limits}; fds=[{details}]; omitted_fds={omitted}"
                ),
                Err(error) => format!("; sidecar open_fds=unavailable ({error}); {limits}"),
            },
            Err(error) => format!("; sidecar open_fds=unavailable ({error}); {limits}"),
        }
    }
}

fn describe_fd(fd: libc::c_int) -> String {
    let mut stat = std::mem::MaybeUninit::<libc::stat>::uninit();
    // SAFETY: fstat writes to a valid stat buffer, read only after success, without opening an fd.
    if unsafe { libc::fstat(fd, stat.as_mut_ptr()) } != 0 {
        return "unavailable".to_owned();
    }
    // SAFETY: fstat initialized the structure.
    let stat = unsafe { stat.assume_init() };
    // readlink needs no new descriptor, even when all descriptor slots are in use.
    let target = std::fs::read_link(format!("/proc/self/fd/{fd}")).ok();
    describe_kind(stat.st_mode, target.as_deref(), stat.st_nlink == 0)
}

fn describe_kind(mode: libc::mode_t, target: Option<&Path>, unlinked: bool) -> String {
    let target = target.map(|path| path.as_os_str().as_bytes());
    if let Some(target) = target.filter(|target| target.starts_with(b"anon_inode:")) {
        return match target {
            b"anon_inode:[eventpoll]" => "epoll",
            b"anon_inode:[eventfd]" => "eventfd",
            b"anon_inode:[timerfd]" => "timerfd",
            b"anon_inode:[signalfd]" => "signalfd",
            b"anon_inode:inotify" | b"anon_inode:[inotify]" => "inotify",
            b"anon_inode:[io_uring]" => "io_uring",
            b"anon_inode:[pidfd]" => "pidfd",
            _ => "anonymous_inode",
        }
        .to_owned();
    }

    match mode & libc::S_IFMT {
        libc::S_IFSOCK => "socket".to_owned(),
        libc::S_IFIFO => "pipe".to_owned(),
        libc::S_IFDIR => "directory".to_owned(),
        libc::S_IFCHR => "character_device".to_owned(),
        libc::S_IFBLK => "block_device".to_owned(),
        libc::S_IFREG => describe_regular_file(target, unlinked),
        _ => "other".to_owned(),
    }
}

fn describe_regular_file(target: Option<&[u8]>, unlinked: bool) -> String {
    let unlinked_marker = if unlinked { ",unlinked=true" } else { "" };
    let Some(target) = target else {
        return format!("regular(extension_unavailable{unlinked_marker})");
    };
    let target = if unlinked {
        target.strip_suffix(b" (deleted)").unwrap_or(target)
    } else {
        target
    };
    if let Some(name) = target
        .strip_prefix(b"/memfd:")
        .or_else(|| target.strip_prefix(b"memfd:"))
    {
        return describe_memory("memfd", OsStr::from_bytes(name), unlinked_marker);
    }
    if target.starts_with(b"/dev/shm/") || target.starts_with(b"/SYSV") {
        let name = Path::new(OsStr::from_bytes(target))
            .file_name()
            .unwrap_or_else(|| OsStr::new("<unavailable>"));
        return describe_memory("shm", name, unlinked_marker);
    }
    let extension = describe_extension(Path::new(OsStr::from_bytes(target)).extension());
    format!("regular({extension}{unlinked_marker})")
}

fn describe_memory(kind: &str, name: &OsStr, unlinked_marker: &str) -> String {
    let name = escape_truncated(name, MAX_MEMORY_NAME_BYTES);
    format!("memory({kind},name=\"{name}\"{unlinked_marker})")
}

fn describe_extension(extension: Option<&OsStr>) -> String {
    let Some(extension) = extension.filter(|extension| !extension.is_empty()) else {
        return "<no extension>".to_owned();
    };
    format!(".{}", escape_truncated(extension, MAX_EXTENSION_BYTES))
}

fn escape_truncated(value: &OsStr, max_bytes: usize) -> String {
    debug_assert!(max_bytes >= 3, "no room for the \"...\" marker");
    let value = value.to_string_lossy();
    let escaped = value.escape_default().to_string();
    if escaped.len() <= max_bytes {
        return escaped;
    }
    let mut truncated = String::new();
    for character in value.chars() {
        let escaped = character.escape_default().to_string();
        if truncated.len() + escaped.len() > max_bytes - 3 {
            break;
        }
        truncated.push_str(&escaped);
    }
    format!("{truncated}...")
}

fn describe_limits() -> String {
    let mut limits = std::mem::MaybeUninit::<libc::rlimit>::uninit();
    // SAFETY: getrlimit writes to a valid rlimit; it is read only after success.
    if unsafe { libc::getrlimit(libc::RLIMIT_NOFILE, limits.as_mut_ptr()) } != 0 {
        return format!("RLIMIT_NOFILE unavailable ({})", io::Error::last_os_error());
    }
    // SAFETY: getrlimit initialized the structure.
    let limits = unsafe { limits.assume_init() };
    let display_limit = |limit: libc::rlim_t| {
        if limit == libc::RLIM_INFINITY {
            "unlimited".to_owned()
        } else {
            limit.to_string()
        }
    };
    format!(
        "RLIMIT_NOFILE soft={} hard={}",
        display_limit(limits.rlim_cur),
        display_limit(limits.rlim_max)
    )
}

// Keep the directory open before EMFILE: opening it on the error path would fail too.
struct Directory(NonNull<libc::DIR>);

impl Directory {
    fn open() -> io::Result<Self> {
        // SAFETY: the path is a valid, NUL-terminated string.
        NonNull::new(unsafe { libc::opendir(c"/proc/self/fd".as_ptr()) })
            .map(Self)
            .ok_or_else(io::Error::last_os_error)
    }

    fn snapshot(&mut self, max_details_bytes: usize) -> io::Result<(usize, String, usize)> {
        let mut count = 0;
        let mut omitted = 0;
        let mut details = String::new();
        // SAFETY: the stream is live and exclusively borrowed for the whole iteration.
        unsafe { libc::rewinddir(self.0.as_ptr()) };
        loop {
            // SAFETY: errno is thread-local. Clearing it distinguishes EOF from an error.
            unsafe { *libc::__errno_location() = 0 };
            // SAFETY: the stream is live and exclusively borrowed.
            let entry = unsafe { libc::readdir(self.0.as_ptr()) };
            if entry.is_null() {
                let error = io::Error::last_os_error();
                return if error.raw_os_error() == Some(0) {
                    Ok((count, details, omitted))
                } else {
                    Err(error)
                };
            }
            // SAFETY: readdir returned a valid entry, used before the next readdir call.
            let name = unsafe { CStr::from_ptr((*entry).d_name.as_ptr()) };
            let Ok(fd) = name.to_str().unwrap_or("").parse::<libc::c_int>() else {
                continue;
            };
            count += 1;
            if omitted > 0 {
                omitted += 1;
                continue;
            }
            let entry = format!("{fd}={}", describe_fd(fd));
            let separator = if details.is_empty() { "" } else { ", " };
            if details.len() + separator.len() + entry.len() > max_details_bytes {
                omitted += 1;
            } else {
                let _ = write!(details, "{separator}{entry}");
            }
        }
    }
}

// SAFETY: Directory owns the stream; all access requires an exclusive reference.
unsafe impl Send for Directory {}

impl Drop for Directory {
    fn drop(&mut self) {
        // SAFETY: this is the owned stream returned by opendir, closed exactly once.
        unsafe { libc::closedir(self.0.as_ptr()) };
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{fs::File, os::fd::AsRawFd};

    #[test]
    fn unrelated_errors_have_no_fd_diagnostics() {
        let mut diagnostics = FdDiagnostics::new();
        let error = anyhow::Error::from(io::Error::from_raw_os_error(libc::ENOENT));
        assert!(diagnostics.describe_error(&error).is_empty());
    }

    #[test]
    fn descriptor_listing_does_not_expose_file_names() {
        let mut diagnostics = FdDiagnostics::new();
        let path = std::env::temp_dir().join(format!(
            "dd-fd-diagnostics-{}-private-customer-record.php",
            std::process::id()
        ));
        let file = File::create_new(&path).unwrap();
        let error = io::Error::from_raw_os_error(libc::EMFILE).into();
        let message = diagnostics.describe_error(&error);
        std::fs::remove_file(&path).unwrap();
        let deleted_message = diagnostics.describe_error(&error);
        assert!(!message.contains("private-customer-record"), "{message}");
        assert!(
            message.contains(&format!("{}=regular(.php)", file.as_raw_fd())),
            "{message}"
        );
        assert!(
            !deleted_message.contains("private-customer-record"),
            "{deleted_message}"
        );
        assert!(
            deleted_message.contains(&format!("{}=regular(.php,unlinked=true)", file.as_raw_fd())),
            "{deleted_message}"
        );
    }

    #[test]
    fn file_types_and_extensions_are_redacted() {
        let cases = [
            (libc::S_IFSOCK, "socket:[123456]", "socket"),
            (libc::S_IFIFO, "pipe:[123456]", "pipe"),
            (libc::S_IFDIR, "/private/customer/", "directory"),
            (libc::S_IFCHR, "/dev/private-device", "character_device"),
            (libc::S_IFBLK, "/dev/private-device", "block_device"),
            (libc::S_IFREG, "/private/customer.LOG", "regular(.LOG)"),
            (
                libc::S_IFREG,
                "/private/customer.log (deleted)",
                "regular(.log,unlinked=true)",
            ),
            (
                libc::S_IFREG,
                "/private/customer.secret_token",
                "regular(.secre...)",
            ),
            (
                libc::S_IFREG,
                "/private/customer",
                "regular(<no extension>)",
            ),
            (
                libc::S_IFREG,
                "/private/.customer",
                "regular(<no extension>)",
            ),
            (
                libc::S_IFREG,
                "/private/customer.",
                "regular(<no extension>)",
            ),
            (
                libc::S_IFREG,
                "/memfd:private-customer.log (deleted)",
                "memory(memfd,name=\"private-custo...\",unlinked=true)",
            ),
            (
                libc::S_IFREG,
                "/dev/shm/private-customer.log",
                "memory(shm,name=\"private-custo...\")",
            ),
            (
                libc::S_IFREG,
                "/dev/shm/private-customer.log (deleted)",
                "memory(shm,name=\"private-custo...\",unlinked=true)",
            ),
            (
                libc::S_IFREG,
                "/dev/shm/dd.rc (deleted)",
                "memory(shm,name=\"dd.rc\",unlinked=true)",
            ),
            (
                libc::S_IFREG,
                "/dev/shm/private-directory/cache",
                "memory(shm,name=\"cache\")",
            ),
            (
                libc::S_IFREG,
                "/dev/shm/a\nb\"",
                "memory(shm,name=\"a\\nb\\\"\")",
            ),
            (
                libc::S_IFREG,
                "/SYSV12345678 (deleted)",
                "memory(shm,name=\"SYSV12345678\",unlinked=true)",
            ),
            (0, "anon_inode:[eventpoll]", "epoll"),
            (0, "anon_inode:[eventfd]", "eventfd"),
            (0, "anon_inode:[timerfd]", "timerfd"),
            (0, "anon_inode:[signalfd]", "signalfd"),
            (0, "anon_inode:inotify", "inotify"),
            (0, "anon_inode:[io_uring]", "io_uring"),
            (0, "anon_inode:[pidfd]", "pidfd"),
            (0, "anon_inode:private-customer", "anonymous_inode"),
            (0, "/private/customer", "other"),
        ];
        for (mode, target, expected) in cases {
            assert_eq!(
                describe_kind(
                    mode,
                    Some(Path::new(target)),
                    target.ends_with(" (deleted)")
                ),
                expected
            );
        }
        assert_eq!(
            describe_kind(libc::S_IFREG, None, true),
            "regular(extension_unavailable,unlinked=true)"
        );
        assert_eq!(
            describe_kind(libc::S_IFREG, None, false),
            "regular(extension_unavailable)"
        );
        let non_utf8 = Path::new(OsStr::from_bytes(b"/private/customer.\xff"));
        assert_eq!(
            describe_kind(libc::S_IFREG, Some(non_utf8), false),
            "regular(.\\u{fffd})"
        );
    }

    #[test]
    fn extensions_are_bounded_and_escaped_without_an_allowlist() {
        for (extension, expected) in [
            ("custom", ".custom"),
            ("abcdefgh", ".abcdefgh"),
            ("abcdefghi", ".abcde..."),
            ("a\nb", ".a\\nb"),
            ("\"\\", ".\\\"\\\\"),
            ("ééé", "...."),
        ] {
            let actual = describe_extension(Some(OsStr::new(extension)));
            assert_eq!(actual, expected);
            assert!(actual.len() <= MAX_EXTENSION_BYTES + 1);
        }
    }

    #[test]
    fn actual_descriptors_are_classified_without_opening_files() {
        let (socket, _peer) = std::os::unix::net::UnixStream::pair().unwrap();
        assert_eq!(describe_fd(socket.as_raw_fd()), "socket");
        // SAFETY: eventfd has no pointer arguments and returns a fresh descriptor.
        let eventfd = unsafe { libc::eventfd(0, libc::EFD_CLOEXEC) };
        assert!(eventfd >= 0);
        // SAFETY: eventfd returned a fresh descriptor, now owned by this File.
        let event = unsafe { <File as std::os::fd::FromRawFd>::from_raw_fd(eventfd) };
        assert_eq!(describe_fd(event.as_raw_fd()), "eventfd");
        // SAFETY: the name is NUL-terminated and memfd_create returns a fresh descriptor.
        let memfd = unsafe { libc::memfd_create(c"private-customer".as_ptr(), libc::MFD_CLOEXEC) };
        assert!(memfd >= 0);
        // SAFETY: memfd_create returned a fresh descriptor, now owned by this File.
        let memory = unsafe { <File as std::os::fd::FromRawFd>::from_raw_fd(memfd) };
        assert_eq!(
            describe_fd(memory.as_raw_fd()),
            "memory(memfd,name=\"private-customer\",unlinked=true)"
        );
        // Negative descriptors are always invalid; a closed positive fd can be reused by another test.
        assert_eq!(describe_fd(-1), "unavailable");
    }

    #[test]
    fn every_redacted_entry_fits_at_a_1024_fd_limit() {
        let longest = [
            describe_kind(
                libc::S_IFREG,
                Some(Path::new("/private/file.abcdefghijklmnop")),
                true,
            ),
            describe_kind(libc::S_IFREG, None, true),
            describe_kind(
                libc::S_IFREG,
                Some(Path::new("/dev/shm/abcdefghijklmnop (deleted)")),
                true,
            ),
        ]
        .into_iter()
        .max_by_key(String::len)
        .unwrap();
        let mut details = String::new();
        for fd in 0..1024 {
            write!(details, "{fd}={longest}, ").unwrap();
        }
        assert!(details.len() <= MAX_FD_DETAILS_BYTES);
        eprintln!(
            "1024 fd entries at the longest label: {} bytes",
            details.len()
        );
    }

    #[test]
    fn reports_types_and_limits_for_wrapped_exhaustion_errors() {
        let mut diagnostics = FdDiagnostics::new();
        let file = File::open("/dev/null").unwrap();
        for code in [libc::EMFILE, libc::ENFILE] {
            let error = anyhow::Error::from(io::Error::from_raw_os_error(code))
                .context("opening RC shared memory");
            let message = diagnostics.describe_error(&error);
            assert!(message.contains("sidecar open_fds="), "{message}");
            assert!(message.contains("RLIMIT_NOFILE soft="), "{message}");
            assert!(message.contains(&format!("{}=character_device", file.as_raw_fd())));
        }
    }

    #[test]
    fn unavailable_procfs_is_reported_along_with_limits() {
        let mut diagnostics = FdDiagnostics {
            directory: Err(io::Error::from_raw_os_error(libc::ENOENT)),
        };
        let error = io::Error::from_raw_os_error(libc::EMFILE).into();
        let message = diagnostics.describe_error(&error);
        assert!(
            message.contains("sidecar open_fds=unavailable"),
            "{message}"
        );
        assert!(message.contains("RLIMIT_NOFILE soft="), "{message}");
    }

    #[test]
    fn large_fd_lists_are_bounded_without_truncating_the_count() {
        let mut diagnostics = FdDiagnostics::new();
        let files: Vec<_> = (0..10).map(|_| File::open("/dev/null").unwrap()).collect();
        let (count, details, omitted) = diagnostics
            .directory
            .as_mut()
            .unwrap()
            .snapshot(64)
            .unwrap();
        assert!(count >= files.len());
        assert!(omitted > 0);
        assert_eq!(details.split(", ").count() + omitted, count);
        assert!(details.len() <= 64);
    }
}
