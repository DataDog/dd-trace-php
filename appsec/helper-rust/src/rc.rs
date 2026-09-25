use std::{
    ffi::{CString, OsStr},
    os::{
        fd::{AsRawFd, OwnedFd},
        unix::ffi::OsStrExt,
    },
    path::{Path, PathBuf},
};

use anyhow::Context;
use base64::{self, Engine};
use libdd_ipc::one_way_shared_memory::{open_named_shm, OneWayShmReader};
use libdd_ipc::platform::NamedShmHandle;

use crate::client::log::debug;

/// Polls the remote config directory the sidecar publishes for one target.
pub struct ConfigPoller {
    path: PathBuf,
    reader: Option<OneWayShmReader<NamedShmHandle, CString>>,
}
impl ConfigPoller {
    pub fn new(shmem_path: &Path) -> Self {
        ConfigPoller {
            path: shmem_path.to_owned(),
            reader: None,
        }
    }

    pub fn poll(&mut self) -> anyhow::Result<Option<ConfigDirectory>> {
        let reader = match &mut self.reader {
            Some(reader) => reader,
            None => {
                // The name is worker-supplied; see validate_shm_name().
                validate_shm_name(&self.path)?;
                let name = CString::new(self.path.as_os_str().as_bytes())
                    .with_context(|| format!("Invalid shared memory name {:?}", self.path))?;
                self.reader
                    .insert(OneWayShmReader::new_with_opener(None, name, |name| {
                        open_named_shm(name).ok()
                    }))
            }
        };
        let (changed, data) = reader.read();
        if !changed {
            debug!("No new remote config in {:?}", self.path);
            return Ok(None);
        }
        let data = data.to_vec();
        Ok(Some(ConfigDirectory::new(data, reader.take_replaced())))
    }
}
impl std::fmt::Debug for ConfigPoller {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ConfigPoller")
            .field("shmem_path", &self.path)
            .finish()
    }
}

pub struct ConfigDirectory {
    data: Vec<u8>,
    replaced: bool,
}
impl ConfigDirectory {
    fn new(data: Vec<u8>, replaced: bool) -> Self {
        ConfigDirectory { data, replaced }
    }

    /// A new writer published this directory; cached config paths must be reloaded.
    pub fn replaced(&self) -> bool {
        self.replaced
    }

    pub fn runtime_id(&self) -> anyhow::Result<&str> {
        if self.data.is_empty() {
            // Cleared shmem state (expired()): no config available, same as unwritten shmem.
            return Ok("");
        }
        self.data.iter().position(|&b| b == b'\n').map_or_else(
            || {
                Err(anyhow::anyhow!(
                    "No LF in remote config; data (base64-encoded): {}",
                    base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(&self.data)
                ))
            },
            |pos| {
                std::str::from_utf8(&self.data[..pos]).with_context(|| {
                    format!(
                        "Invalid UTF-8 in runtime_id of remote config; data (base64-encoded): {}",
                        base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(&self.data[..pos])
                    )
                })
            },
        )
    }

    pub fn iter(&self) -> anyhow::Result<impl Iterator<Item = anyhow::Result<Config<'_>>> + '_> {
        if self.data.is_empty() {
            // Cleared shmem state (expired()): no config available, same as unwritten shmem.
            return Ok(ConfigIter {
                data: &self.data[..],
                pos: 0,
            });
        }
        self.data.iter().position(|&b| b == b'\n').map_or_else(
            || {
                Err(anyhow::anyhow!(
                    "No LF in remote config; data (base64-encoded): {}",
                    base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(&self.data)
                ))
            },
            |pos| {
                Ok(ConfigIter {
                    data: &self.data[pos + 1..],
                    pos: 0,
                })
            },
        )
    }
}
struct ConfigIter<'a> {
    data: &'a [u8],
    pos: usize,
}
impl<'a> Iterator for ConfigIter<'a> {
    type Item = anyhow::Result<Config<'a>>;

    fn next(&mut self) -> Option<Self::Item> {
        if self.pos >= self.data.len() {
            return None;
        }

        let slice = &self.data[self.pos..];
        let end = slice.iter().position(|&b| b == b'\n');

        match end {
            Some(end) => {
                self.pos += end + 1;
                Some(Config::from_line(&slice[..end]))
            }
            None => {
                self.pos = self.data.len();
                Some(Err(anyhow::anyhow!(
                    "Missing LF iterating remote config lines"
                )))
            }
        }
    }
}

#[derive(Debug, PartialEq, Eq, Hash, Clone)]
pub struct RcPath(String);
impl RcPath {
    pub fn as_str(&self) -> &str {
        &self.0
    }
}
impl AsRef<str> for RcPath {
    fn as_ref(&self) -> &str {
        self.as_str()
    }
}

#[derive(Debug, PartialEq, Eq)]
pub struct Config<'a> {
    shm_path: &'a Path,
    rc_path: RcPath,
}
impl<'a> Config<'a> {
    pub fn rc_path(&self) -> &RcPath {
        &self.rc_path
    }

    pub fn shm_path(&self) -> &Path {
        self.shm_path
    }

    fn from_line(line: &'a [u8]) -> anyhow::Result<Self> {
        // Find the first ':'
        let pos = line
            .iter()
            .position(|&b| b == b':')
            .context("Invalid config line (no colon)")?;
        let shm_path = &line[..pos];

        // Find the second ':'
        let pos2 = line[pos + 1..]
            .iter()
            .position(|&b| b == b':')
            .map(|p| p + pos + 1)
            .context("Invalid config line (no second colon)")?;

        // Extract and parse limiter_idx
        let limiter_idx_str = &line[pos + 1..pos2];
        let _limiter_idx = std::str::from_utf8(limiter_idx_str)
            .context("Invalid UTF-8 in limiter_idx")?
            .parse::<u32>()
            .context("Invalid config line (limiter_idx)")?;

        // Extract and decode rc_path (URL-safe base64, no padding)
        let rc_path_encoded = &line[pos2 + 1..];
        let rc_path = base64::engine::general_purpose::URL_SAFE_NO_PAD
            .decode(rc_path_encoded)
            .with_context(|| "Failed to decode base64 rc_path")
            .and_then(|bytes| String::from_utf8(bytes).context("Invalid UTF-8 for rc_path"))?;

        Ok(Config {
            shm_path: Path::new(OsStr::from_bytes(shm_path)),
            rc_path: RcPath(rc_path),
        })
    }

    pub fn read(&self) -> anyhow::Result<Shmem> {
        let mut shmem = Shmem::new(self.shm_path);
        shmem
            .open()
            .with_context(|| format!("Failed to open shared memory file {:?}", self.shm_path))?;
        let size = shmem
            .fd_size()
            .with_context(|| format!("Failed to get shared memory size of {:?}", self.shm_path))?;
        shmem
            .mmap(size)
            .with_context(|| format!("Failed to map shared memory file {:?}", self.shm_path))?;
        Ok(shmem)
    }

    pub fn product(&'a self) -> Product<'a> {
        let s = self.rc_path.as_str();
        if let Some(remainder) = s.strip_prefix("datadog/") {
            if let Some((_, remainder)) = remainder.split_once("/") {
                if let Some((product, _)) = remainder.split_once("/") {
                    return Product(product);
                }
            }
        } else if let Some(remainder) = s.strip_prefix("employee/") {
            if let Some((product, _)) = remainder.split_once('/') {
                return Product(product);
            }
        }

        Product("UNKNOWN")
    }
}

#[derive(Debug, PartialEq, Eq)]
pub struct Product<'a>(pub &'a str);

impl<'a> Product<'a> {
    pub fn name(&self) -> &'a str {
        self.0
    }
}

#[derive(Debug, Clone)]
pub struct ParsedConfigKey {
    pub product: String,
    pub config_id: String,
}

impl ParsedConfigKey {
    pub fn from_rc_path(rc_path: &RcPath) -> Option<Self> {
        // Format: (datadog/<org_id> | employee)/<PRODUCT>/<config_id>/<name>
        let parts: Vec<&str> = rc_path.as_str().split('/').collect();

        if parts.len() >= 4 && parts[0] == "datadog" {
            // datadog/<org_id>/<PRODUCT>/<config_id>/...
            Some(ParsedConfigKey {
                product: parts[2].to_ascii_lowercase(),
                config_id: parts[3].to_string(),
            })
        } else if parts.len() >= 3 && parts[0] == "employee" {
            // employee/<PRODUCT>/<config_id>/...
            Some(ParsedConfigKey {
                product: parts[1].to_ascii_lowercase(),
                config_id: parts[2].to_string(),
            })
        } else {
            None
        }
    }
}

pub struct Shmem {
    path: PathBuf,
    fd: Option<OwnedFd>,
    ptr: *const u8,
    size: usize, // mapped size
}

/// Require a POSIX shared-memory name: one leading slash and one component, other than `.` or `..`.
fn validate_shm_name(path: &Path) -> anyhow::Result<()> {
    let bytes = path.as_os_str().as_bytes();
    anyhow::ensure!(
        bytes.first() == Some(&b'/'),
        "shared-memory name must start with '/': {path:?}"
    );
    let name = &bytes[1..];
    anyhow::ensure!(
        !name.is_empty(),
        "shared-memory name must have a basename: {path:?}"
    );
    anyhow::ensure!(
        !name.contains(&b'/'),
        "shared-memory name must not contain an interior '/': {path:?}"
    );
    anyhow::ensure!(
        name != b"." && name != b"..",
        "invalid shared-memory basename: {path:?}"
    );
    Ok(())
}

impl Shmem {
    fn new(path: &Path) -> Self {
        Shmem {
            path: path.to_owned(),
            fd: None,
            ptr: std::ptr::null(),
            size: 0,
        }
    }

    fn open(&mut self) -> anyhow::Result<()> {
        if self.fd.is_some() {
            return Ok(());
        }

        // CentOS 7's glibc accepts interior slashes, allowing traversal out of /dev/shm. Validate
        // worker-supplied names before passing them to libc.
        validate_shm_name(&self.path)?;

        debug!("Opening shared memory file {:?}", self.path);
        let path_cstr = CString::new(self.path.as_os_str().as_bytes())
            .with_context(|| format!("Failed to convert path {:?} to CString", self.path))?;

        // Exactly as the sidecar named it: the same platform spelling of the name and the same
        // filesystem fallback, and the same refusal of segments another user could have made.
        let fd = libdd_ipc::platform::open_named_shm_fd(&path_cstr)
            .map_err(|err| anyhow::Error::from(err).context("shm_open() failed"))?;
        self.fd = Some(fd);
        Ok(())
    }

    fn mmap(&mut self, size: usize) -> anyhow::Result<()> {
        if self.fd.is_none() {
            self.open()?;
        } else {
            self.unmap()?;
        }
        let fd = self
            .fd
            .as_ref()
            .expect("fd must be present after open")
            .as_raw_fd();
        let ptr = unsafe {
            libc::mmap(
                std::ptr::null_mut(),
                size,
                libc::PROT_READ,
                libc::MAP_SHARED,
                fd,
                0,
            )
        };
        if ptr == libc::MAP_FAILED {
            return Err(anyhow::anyhow!(
                "mmap failed: {}",
                std::io::Error::last_os_error()
            ));
        }
        self.ptr = ptr as *mut u8;
        self.size = size;
        debug!(
            "Mapped shared memory file {:?}: size {}",
            self.path, self.size
        );
        Ok(())
    }

    fn unmap(&mut self) -> anyhow::Result<()> {
        if self.ptr.is_null() {
            return Ok(());
        }
        let ret = unsafe { libc::munmap(self.ptr as *mut libc::c_void, self.size) };
        if ret != 0 {
            anyhow::bail!("munmap failed: {}", std::io::Error::last_os_error());
        }
        self.ptr = std::ptr::null();
        self.size = 0;
        Ok(())
    }

    /// SAFETY: this function is unsafe because the data behind the slice can in
    /// principle change at any time
    pub unsafe fn as_slice(&self) -> &[u8] {
        if self.ptr.is_null() {
            return &[];
        }
        unsafe { std::slice::from_raw_parts(self.ptr, self.size) }
    }

    fn fd_size(&self) -> anyhow::Result<usize> {
        if self.fd.is_none() {
            anyhow::bail!("Shared memory file not open");
        }
        let mut statbuf = std::mem::MaybeUninit::uninit();
        let fd = self
            .fd
            .as_ref()
            .expect("logically, fd must be present")
            .as_raw_fd();
        let res = unsafe { libc::fstat(fd, statbuf.as_mut_ptr()) };
        if res != 0 {
            return Err(anyhow::anyhow!(
                "fstat failed: {}",
                std::io::Error::last_os_error()
            ));
        }
        Ok(unsafe { statbuf.assume_init().st_size as usize })
    }
}
impl Drop for Shmem {
    fn drop(&mut self) {
        if !self.ptr.is_null() {
            unsafe {
                libc::munmap(self.ptr as *mut libc::c_void, self.size);
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use libdd_ipc::one_way_shared_memory::OneWayShmWriter;
    use std::ffi::CString;
    use std::os::fd::{AsFd, AsRawFd, BorrowedFd, FromRawFd};
    use std::os::unix::ffi::OsStrExt;

    #[test]
    fn validate_shm_name_accepts_legit_and_rejects_traversal() {
        // Names the sidecar actually generates: a leading slash and one component.
        assert!(validate_shm_name(Path::new("/ddrc0-AbC_-9")).is_ok());
        assert!(validate_shm_name(Path::new("/ddpathreview-1234")).is_ok());
        assert!(validate_shm_name(Path::new("/../../proc/self/fd/7/private.txt")).is_err());
        assert!(validate_shm_name(Path::new("/a/b")).is_err());
        assert!(validate_shm_name(Path::new("relative")).is_err());
        assert!(validate_shm_name(Path::new("/")).is_err());
        assert!(validate_shm_name(Path::new("/..")).is_err());
        assert!(validate_shm_name(Path::new("/.")).is_err());
    }

    /// Match the sidecar's macOS naming convention.
    fn native_name(name: &str) -> CString {
        #[cfg(target_os = "macos")]
        let name = name.strip_prefix('/').unwrap_or(name);
        CString::new(name.as_bytes()).unwrap()
    }

    fn shm_unlink(name: &str) {
        unsafe {
            let _ = libc::shm_unlink(native_name(name).as_ptr());
        }
    }

    fn shm_create_and_write(name: &str, content: &[u8]) -> anyhow::Result<()> {
        let c_name = native_name(name);
        unsafe {
            // Best-effort cleanup in case it already exists
            libc::shm_unlink(c_name.as_ptr());
        }
        let fd = unsafe {
            libc::shm_open(
                c_name.as_ptr(),
                libc::O_CREAT | libc::O_RDWR,
                0o600 as libc::c_uint,
            )
        };
        if fd < 0 {
            anyhow::bail!(
                "shm_open create failed: {}",
                std::io::Error::last_os_error()
            );
        }
        let fd = unsafe { OwnedFd::from_raw_fd(fd) };
        unsafe { shm_write_via_mmap(fd.as_fd(), content) }
    }

    // mac os doesn't support write() directly
    unsafe fn shm_write_via_mmap(fd: BorrowedFd, content: &[u8]) -> anyhow::Result<()> {
        let raw_fd = fd.as_raw_fd();
        if libc::ftruncate(raw_fd, content.len() as i64) != 0 {
            anyhow::bail!("ftruncate failed: {}", std::io::Error::last_os_error());
        }
        let ptr = libc::mmap(
            std::ptr::null_mut(),
            content.len(),
            libc::PROT_WRITE,
            libc::MAP_SHARED,
            raw_fd,
            0,
        );
        if ptr == libc::MAP_FAILED {
            anyhow::bail!("mmap failed: {}", std::io::Error::last_os_error());
        }
        std::ptr::copy_nonoverlapping(content.as_ptr(), ptr as *mut u8, content.len());
        if libc::munmap(ptr, content.len()) != 0 {
            anyhow::bail!("munmap failed: {}", std::io::Error::last_os_error());
        }
        Ok(())
    }

    /// Keep the returned writer alive while the test uses the directory.
    fn shm_create_config_dir_with_raw_payload(
        name: &str,
        body: &[u8],
    ) -> anyhow::Result<OneWayShmWriter<NamedShmHandle>> {
        let writer = OneWayShmWriter::<NamedShmHandle>::new(CString::new(name)?)?;
        anyhow::ensure!(writer.write(body), "could not publish the directory");
        Ok(writer)
    }

    fn shm_create_and_write_config_dir(
        name: &str,
        runtime_id: &str,
        lines: &[String],
    ) -> anyhow::Result<OneWayShmWriter<NamedShmHandle>> {
        let mut body = Vec::new();
        body.extend_from_slice(runtime_id.as_bytes());
        body.push(b'\n');
        for l in lines {
            body.extend_from_slice(l.as_bytes());
            body.push(b'\n');
        }
        shm_create_config_dir_with_raw_payload(name, &body)
    }

    #[test]
    fn config_directory_handles_cleared_shmem() -> anyhow::Result<()> {
        // expired() calls writer.write(&[]), which stores size=1 with just the trailing NUL byte.
        // This cleared state means "no config available" and must not be treated as an error.
        let name = "/helper_rust_cfg_cleared_state";
        let _writer = shm_create_config_dir_with_raw_payload(name, b"")?;

        let mut poller = ConfigPoller::new(Path::new(OsStr::from_bytes(name.as_bytes())));
        let cfg_dir = poller
            .poll()?
            .context("expected config snapshot when seq advanced")?;

        assert_eq!(cfg_dir.runtime_id()?, "");
        let configs: Vec<_> = cfg_dir.iter()?.collect::<anyhow::Result<Vec<_>>>()?;
        assert!(configs.is_empty());

        shm_unlink(name);
        Ok(())
    }

    #[test]
    fn config_directory_iter_errors_when_payload_has_no_lf() -> anyhow::Result<()> {
        let outer = "/helper_rust_cfg_no_lf_iter";
        let _writer =
            shm_create_config_dir_with_raw_payload(outer, b"corrupt_or_partial_rc_index")?;

        let mut poller = ConfigPoller::new(Path::new(OsStr::from_bytes(outer.as_bytes())));
        let cfg_dir = poller
            .poll()?
            .context("expected config snapshot when seq advanced")?;

        let err = match cfg_dir.iter() {
            Ok(_) => panic!("iter() must fail when the directory payload has no newline"),
            Err(e) => e,
        };
        assert!(
            err.to_string().contains("No LF in remote config"),
            "unexpected error: {err:#}"
        );

        shm_unlink(outer);
        Ok(())
    }

    #[test]
    fn test_config_poller_reads_runtime_id_and_files() -> anyhow::Result<()> {
        // Setup
        let outer = "/helper_rust_outer_test_cfg";
        let inner1 = "/helper_rust_inner_test_cfg_1";
        let inner2 = "/helper_rust_inner_test_cfg_2";

        let inner1_content = b"FILE1_CONTENT";
        let inner2_content = b"FILE2_CONTENT";
        shm_create_and_write(inner1, inner1_content)?;
        shm_create_and_write(inner2, inner2_content)?;

        let runtime_id = "runtime-123";
        let rc1_path = "employee/apm/config1";
        let rc2_path = "employee/profiler/config2";
        let rc1_b64 = base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(rc1_path.as_bytes());
        let rc2_b64 = base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(rc2_path.as_bytes());

        let entries = vec![
            format!("{}:{}:{}", inner1, 0, rc1_b64),
            format!("{}:{}:{}", inner2, 1, rc2_b64),
        ];

        let _writer = shm_create_and_write_config_dir(outer, runtime_id, &entries)?;

        // Run poll()
        let mut poller = ConfigPoller::new(Path::new(OsStr::from_bytes(outer.as_bytes())));
        let cfg_dir_opt = poller.poll()?;
        let cfg_dir = cfg_dir_opt.context("Expected Some(ConfigDirectory) from poll")?;

        // Assertions
        assert_eq!(cfg_dir.runtime_id()?, runtime_id);

        let mut got = Vec::new();
        for cfg_res in cfg_dir.iter()? {
            let cfg = cfg_res?;
            let shmem = cfg.read()?;
            let data = unsafe { shmem.as_slice() };
            got.push((cfg.rc_path.clone(), data.to_vec()));
        }

        assert_eq!(got.len(), 2);
        // Order should match insertion
        assert_eq!(got[0].0.as_str(), rc1_path);
        #[cfg(target_os = "macos")]
        {
            // On macOS, fstat() returns padded size (16KB min), so compare only the actual content
            assert_eq!(&got[0].1[..inner1_content.len()], inner1_content);
        }
        #[cfg(not(target_os = "macos"))]
        {
            assert_eq!(got[0].1, inner1_content);
        }
        assert_eq!(got[1].0.as_str(), rc2_path);
        #[cfg(target_os = "macos")]
        {
            assert_eq!(&got[1].1[..inner2_content.len()], inner2_content);
        }
        #[cfg(not(target_os = "macos"))]
        {
            assert_eq!(got[1].1, inner2_content);
        }

        shm_unlink(outer);
        shm_unlink(inner1);
        shm_unlink(inner2);

        Ok(())
    }

    fn writer_name(tag: &str) -> CString {
        CString::new(format!("/helper_rust_{tag}_{}", std::process::id())).unwrap()
    }

    fn poller_for(name: &CString) -> ConfigPoller {
        ConfigPoller::new(Path::new(OsStr::from_bytes(name.as_bytes())))
    }

    /// A poller holding a directory must move on when its writer is replaced - by a restarted
    /// sidecar, say - even though the new directory's sequence starts over below the old one.
    #[test]
    fn a_poller_follows_a_replaced_directory() -> anyhow::Result<()> {
        use libdd_ipc::one_way_shared_memory::OneWayShmWriter;
        use libdd_ipc::platform::NamedShmHandle;

        let name = writer_name("replaced");
        let old = OneWayShmWriter::<NamedShmHandle>::new(name.clone())?;
        for _ in 0..3 {
            assert!(old.write(b"old-runtime\n"));
        }
        let mut poller = poller_for(&name);
        let snapshot = poller.poll()?.context("the first directory")?;
        assert_eq!(snapshot.runtime_id()?, "old-runtime");
        assert!(!snapshot.replaced());
        assert!(poller.poll()?.is_none());

        let new = OneWayShmWriter::<NamedShmHandle>::new(name.clone())?;
        assert!(new.write(b"new-runtime\n"));
        let snapshot = poller.poll()?.context("the replacement directory")?;
        assert_eq!(snapshot.runtime_id()?, "new-runtime");
        assert!(snapshot.replaced(), "a new writer starts over");
        assert!(poller.poll()?.is_none());

        assert!(new.write(b"newer-runtime\n"));
        let snapshot = poller.poll()?.context("an ordinary update")?;
        assert_eq!(snapshot.runtime_id()?, "newer-runtime");
        assert!(!snapshot.replaced());
        Ok(())
    }

    /// A retired directory is odd-numbered forever. It must not be spun on like a write in
    /// progress, nor read - and with no successor yet, the poller just has nothing new.
    #[test]
    fn a_retired_directory_without_successor_is_not_spun_on() -> anyhow::Result<()> {
        use libdd_ipc::one_way_shared_memory::OneWayShmWriter;
        use libdd_ipc::platform::NamedShmHandle;

        let name = writer_name("orphaned");
        let old = OneWayShmWriter::<NamedShmHandle>::new(name.clone())?;
        assert!(old.write(b"old-runtime\n"));
        let mut poller = poller_for(&name);
        assert_eq!(
            poller.poll()?.context("first")?.runtime_id()?,
            "old-runtime"
        );

        // Replaced, and the replacement gone again before the poller looked.
        let new = OneWayShmWriter::<NamedShmHandle>::new(name.clone())?;
        assert!(new.write(b"new-runtime\n"));
        std::mem::forget(old);
        drop(new);

        assert!(poller.poll()?.is_none(), "nothing to move to yet");
        assert!(poller.poll()?.is_none());

        let next = OneWayShmWriter::<NamedShmHandle>::new(name.clone())?;
        assert!(next.write(b"next-runtime\n"));
        let snapshot = poller.poll()?.context("the next writer")?;
        assert_eq!(snapshot.runtime_id()?, "next-runtime");
        assert!(snapshot.replaced());
        Ok(())
    }

    /// A writer that clears the directory on its way out (the target is no longer fetched)
    /// must have that seen - and whoever writes under the name afterwards too.
    #[test]
    fn the_final_directory_of_an_ended_writer_is_read() -> anyhow::Result<()> {
        use libdd_ipc::one_way_shared_memory::OneWayShmWriter;
        use libdd_ipc::platform::NamedShmHandle;

        let name = writer_name("ended");
        let writer = OneWayShmWriter::<NamedShmHandle>::new(name.clone())?;
        assert!(writer.write(b"runtime\n"));
        let mut poller = poller_for(&name);
        assert_eq!(poller.poll()?.context("first")?.runtime_id()?, "runtime");

        assert!(writer.write(b""));
        drop(writer);
        let snapshot = poller.poll()?.context("the cleared directory")?;
        assert_eq!(snapshot.runtime_id()?, "");
        assert!(poller.poll()?.is_none());

        let writer = OneWayShmWriter::<NamedShmHandle>::new(name.clone())?;
        assert!(writer.write(b"again\n"));
        let snapshot = poller.poll()?.context("the next writer")?;
        assert_eq!(snapshot.runtime_id()?, "again");
        Ok(())
    }
}
