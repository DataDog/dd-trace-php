use anyhow::{Context, Result};
use std::fs::{File, OpenOptions};
use std::io::{Read, Write};
use std::os::unix::io::AsRawFd;
use std::path::PathBuf;

/// A lock file that ensures only one instance of the helper is running
///
/// The lock is acquired using flock and automatically released when dropped.
pub struct LockFile {
    file: File,
    path: PathBuf,
}

impl LockFile {
    /// Acquire an exclusive lock on the specified file
    ///
    /// Locking is achieved using flock(2).
    pub fn acquire(path: PathBuf) -> Result<Self> {
        // Open or create the lock file
        let mut file = OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(false)
            .open(&path)
            .with_context(|| format!("Failed to open lock file: {:?}", path))?;

        // Try to acquire exclusive lock (non-blocking)
        let fd = file.as_raw_fd();
        let result = unsafe { libc::flock(fd, libc::LOCK_EX | libc::LOCK_NB) };

        if result == -1 {
            let err = std::io::Error::last_os_error();

            // EWOULDBLOCK means another process holds the lock
            if err.raw_os_error() == Some(libc::EWOULDBLOCK) {
                let mut pid_str = String::new();
                file.read_to_string(&mut pid_str).with_context(|| {
                    format!(
                        "Failed to acquire lock and read PID from lock file: {:?}",
                        path
                    )
                })?;

                if let Ok(pid) = pid_str.trim().parse::<i32>() {
                    let proc_exists = unsafe { libc::kill(pid, 0) } == 0;

                    if proc_exists {
                        anyhow::bail!("Another helper instance is already running (PID: {})", pid);
                    } else {
                        anyhow::bail!(
                            "The lock file is behind held, but its written PID {} is not running",
                            pid
                        );
                    }
                } else {
                    anyhow::bail!(
                        "Lock file {:?} is locked and contains invalid PID: {:?}",
                        path,
                        pid_str
                    );
                }
            } else {
                return Err(err).with_context(|| "Failed to flock lock file");
            }
        }

        let our_pid = unsafe { libc::getpid() };
        file.set_len(0)
            .with_context(|| "Failed to truncate lock file")?;
        writeln!(file, "{}", our_pid).with_context(|| "Failed to write PID to lock file")?;
        file.flush().with_context(|| "Failed to flush lock file")?;

        log::info!("Acquired lock file: {:?} (PID: {})", path, our_pid);

        Ok(LockFile { file, path })
    }
}

impl Drop for LockFile {
    fn drop(&mut self) {
        let res = unsafe { libc::flock(self.file.as_raw_fd(), libc::LOCK_UN) };
        if res == -1 {
            log::warn!(
                "Failed to release lock on file {:?}: {}",
                self.path,
                std::io::Error::last_os_error()
            );
        }
        if let Err(e) = std::fs::remove_file(&self.path) {
            log::warn!("Failed to remove lock file {:?}: {}", self.path, e);
        } else {
            log::info!("Removed lock file: {:?}", self.path);
        }
    }
}

/// Check if an abstract namespace socket is already in use by attempting to connect
///
/// Returns Ok(()) if the socket is not in use (i.e., we can proceed).
/// Returns Err if another helper is already running on this socket.
#[cfg(target_os = "linux")]
pub fn ensure_abstract_socket_unique(socket_path: &[u8]) -> Result<()> {
    use std::os::linux::net::SocketAddrExt;
    use std::os::unix::net::{SocketAddr, UnixStream};

    if socket_path.first() != Some(&b'\0') {
        anyhow::bail!("Socket path is not an abstract namespace socket");
    }

    let addr = SocketAddr::from_abstract_name(&socket_path[1..])?;
    match UnixStream::connect_addr(&addr) {
        Ok(_) => {
            anyhow::bail!(
                "Another helper is already running on abstract socket {}",
                String::from_utf8_lossy(&socket_path[1..])
            );
        }
        Err(e)
            if e.kind() == std::io::ErrorKind::ConnectionRefused
                || e.kind() == std::io::ErrorKind::NotFound =>
        {
            log::debug!(
                "No helper running on abstract socket {}, proceeding",
                String::from_utf8_lossy(&socket_path[1..])
            );
            Ok(())
        }
        Err(e) => Err(e).with_context(|| {
            format!(
                "Failed to check abstract socket uniqueness for {}",
                String::from_utf8_lossy(&socket_path[1..])
            )
        }),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;

    #[test]
    fn test_lock_file_acquire_and_release() {
        let lock_path = PathBuf::from("/tmp/test_ddappsec.lock");

        let _ = fs::remove_file(&lock_path);

        {
            let _lock = LockFile::acquire(lock_path.clone()).unwrap();
            assert!(lock_path.exists());

            let content = fs::read_to_string(&lock_path).unwrap();
            let pid: i32 = content.trim().parse().unwrap();
            assert_eq!(pid, unsafe { libc::getpid() });
        }

        assert!(!lock_path.exists());
    }

    #[test]
    fn test_lock_file_prevents_double_lock() {
        let lock_path = PathBuf::from("/tmp/test_ddappsec2.lock");

        // Clean up any existing lock file
        let _ = fs::remove_file(&lock_path);

        let _lock1 = LockFile::acquire(lock_path.clone()).unwrap();

        // Second lock should fail
        let result = LockFile::acquire(lock_path.clone());
        assert!(result.is_err());

        // Clean up
        drop(_lock1);
        assert!(!lock_path.exists());
    }
}
