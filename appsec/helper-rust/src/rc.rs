use std::{
    ffi::{CString, OsStr},
    os::{
        fd::{AsRawFd, FromRawFd, OwnedFd},
        unix::ffi::OsStrExt,
    },
    path::{Path, PathBuf},
    sync::atomic::{fence, AtomicU64, AtomicUsize, Ordering},
    thread,
};

use anyhow::Context;
use base64::{self, Engine};

use crate::client::log::debug;

pub struct ConfigPoller {
    reader: ConfigReader,
}
impl ConfigPoller {
    pub fn new(shmem_path: &Path) -> Self {
        let shmem = Shmem::new(shmem_path);
        ConfigPoller {
            reader: ConfigReader { shmem, last_seq: 0 },
        }
    }

    pub fn poll(&mut self) -> anyhow::Result<Option<ConfigDirectory>> {
        let res_maybe_cfg_dir = self.reader.read();
        match res_maybe_cfg_dir {
            Ok(config) => Ok(config),
            Err(err) => {
                if let Some(io_err) = err.downcast_ref::<std::io::Error>() {
                    if io_err.kind() == std::io::ErrorKind::NotFound {
                        debug!(
                            "File not found while reading remote config {:?}: {}",
                            self, err
                        );
                        return Ok(None);
                    }
                }
                Err(err).with_context(|| format!("Failed to read remote config: {:?}", self))
            }
        }
    }
}
impl std::fmt::Debug for ConfigPoller {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ConfigPoller")
            .field("shmem_path", &self.reader.shmem.path)
            .finish()
    }
}

struct ConfigReader {
    shmem: Shmem,
    last_seq: u64,
}
unsafe impl Send for ConfigReader {}
unsafe impl Sync for ConfigReader {}

impl ConfigReader {
    fn read(&mut self) -> anyhow::Result<Option<ConfigDirectory>> {
        debug!("Reading config from shared memory {:?}", self.shmem.path);

        self.shmem
            .open()
            .with_context(|| format!("Failed to open shared memory file {:?}", self.shmem.path))?;
        // ensure we have at least the header mapped
        let fd_size = self.shmem.fd_size().with_context(|| {
            format!("Failed to get shared memory size for {:?}", self.shmem.path)
        })?;
        if fd_size < std::mem::size_of::<ConfigDirHeaderInMem>() {
            anyhow::bail!("Shared memory file is too small to contain the header");
        }
        self.shmem
            .mmap(fd_size)
            .with_context(|| format!("Failed to mmap {:?}, size {}", self.shmem.path, fd_size))?;

        loop {
            // busy loop...
            // this implements a seqlock
            //
            // The writer goes like this:
            // w1. seq += 1 (acq-release)
            // w2. write data
            // w3. seq += 1 (release)
            //
            // The reader does this:
            // r1. read seq (acquire)
            // r2. read data
            // r3. fence (acquire)
            // r4. read seq (relaxed)
            // r5. if seq is odd or changed retry
            // see https://github.com/DataDog/libdatadog/pull/831

            let mut mem_as_header = unsafe { self.shmem.as_type::<ConfigDirHeaderInMem>()? };
            // acquire: synchronize with release on seq increments
            // If the value is even we're guaranteed to see the data written just
            // before the release (or later data)
            let new_seq = mem_as_header.seq.load(Ordering::Acquire);

            if new_seq & 1 == 1 {
                debug!("Sequence number is odd: {}", new_seq);
                thread::yield_now();
                continue;
            }

            if new_seq == self.last_seq {
                debug!("Sequence number did not advance: {}", new_seq);
                return Ok(None);
            }

            let new_size = mem_as_header.size.load(Ordering::Relaxed);
            let min_mapped_size = new_size + std::mem::size_of::<ConfigDirHeaderInMem>();
            let cur_mapped_size = self.shmem.mapped_size();
            if cur_mapped_size < min_mapped_size {
                let fd_size = self.shmem.fd_size()?;
                if min_mapped_size > fd_size {
                    anyhow::bail!(
                        "Shared memory file is too small relatively to \
                                   the declared size of the payload. File size: {}, \
                                   declared payload size: {} -> min file size: {}",
                        fd_size,
                        new_size,
                        min_mapped_size
                    );
                }

                // remap
                self.shmem
                    .mmap(fd_size)
                    .with_context(|| "Failed to map shared memory with new size")?;
                mem_as_header = unsafe { self.shmem.as_type::<ConfigDirHeaderInMem>()? };
            }

            // TODO: this should be done with core::intrinsics::atomic_load_relaxed
            let mem = unsafe { self.shmem.as_slice() };
            // new_size is payload size only (not including header).
            // The writer adds a trailing zero byte for C compatibility; exclude it.
            let payload_start = std::mem::size_of::<ConfigDirHeaderInMem>();
            let payload_end = payload_start + new_size - 1;
            let copied_data: Vec<u8> = mem[payload_start..payload_end].to_vec();

            // adds a LoadLoad barrier, so the following relaxed load
            // cannot be moved before the read for copied_data
            fence(Ordering::Acquire);
            let final_seq = mem_as_header.seq.load(Ordering::Relaxed);
            if final_seq > new_seq {
                debug!(
                    "Sequence advanced while reading: {} -> {}; trying again",
                    new_seq, final_seq
                );
                thread::yield_now();
                continue;
            }

            debug!(
                "Read config from shared memory {:?}: seq {}, size {}",
                self.shmem.path, new_seq, new_size
            );

            self.last_seq = new_seq;
            return Ok(Some(ConfigDirectory::new(copied_data)));
        }
    }
}

pub struct ConfigDirectory {
    data: Vec<u8>,
}
impl ConfigDirectory {
    fn new(data: Vec<u8>) -> Self {
        ConfigDirectory { data }
    }

    pub fn runtime_id(&self) -> anyhow::Result<&str> {
        self.data.iter().position(|&b| b == b'\n').map_or_else(
            || Err(anyhow::anyhow!("No LF in remote config")),
            |pos| {
                std::str::from_utf8(&self.data[..pos])
                    .context("Invalid UTF-8 in runtime_id of remote config")
            },
        )
    }

    pub fn iter(&self) -> anyhow::Result<impl Iterator<Item = anyhow::Result<Config<'_>>> + '_> {
        self.data.iter().position(|&b| b == b'\n').map_or_else(
            || Err(anyhow::anyhow!("No LF in remote config")),
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

#[derive(Debug, PartialEq, Eq)]
pub struct Config<'a> {
    shm_path: &'a Path,
    rc_path: String,
}
impl<'a> Config<'a> {
    pub fn rc_path(&self) -> &str {
        &self.rc_path
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
            rc_path,
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
pub struct Product<'a>(&'a str);

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
    pub fn from_rc_path(rc_path: &str) -> Option<Self> {
        // Format: (datadog/<org_id> | employee)/<PRODUCT>/<config_id>/<name>
        let parts: Vec<&str> = rc_path.split('/').collect();

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

#[repr(C)]
#[derive(Debug)]
struct ConfigDirHeaderInMem {
    pub seq: AtomicU64,
    pub size: AtomicUsize,
}

pub struct Shmem {
    path: PathBuf,
    fd: Option<OwnedFd>,
    ptr: *const u8,
    size: usize, // mapped size
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

        debug!("Opening shared memory file {:?}", self.path);
        let path_cstr = CString::new(self.path.as_os_str().as_bytes())
            .with_context(|| format!("Failed to convert path {:?} to CString", self.path))?;

        let fd = unsafe { libc::shm_open(path_cstr.as_ptr(), libc::O_RDONLY, 0) };
        if fd < 0 {
            let err: anyhow::Error = std::io::Error::last_os_error().into();
            return Err(err.context("shm_open() failed"));
        }
        // SAFETY: fd is a valid file descriptor returned by shm_open on success
        self.fd = Some(unsafe { OwnedFd::from_raw_fd(fd) });
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

    pub unsafe fn as_type<T>(&self) -> anyhow::Result<&'_ T> {
        if self.ptr.is_null() {
            anyhow::bail!("Shared memory not mapped");
        }
        if self.size < std::mem::size_of::<T>() {
            anyhow::bail!(
                "Shared memory too small for type. Expected at least {} bytes, got {} bytes",
                std::mem::size_of::<T>(),
                self.size
            );
        }
        unsafe { Ok(&*(self.ptr as *const T)) }
    }

    fn mapped_size(&self) -> usize {
        self.size
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
        // OwnedFd (when present) will be closed automatically on drop
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::CString;
    use std::os::fd::{AsFd, AsRawFd, BorrowedFd};
    use std::os::unix::ffi::OsStrExt;

    fn shm_create_and_write(name: &str, content: &[u8]) -> anyhow::Result<()> {
        let c_name = CString::new(name.as_bytes()).unwrap();
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
        let result = unsafe { shm_write_via_mmap(fd.as_fd(), content) };
        result
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

    fn shm_create_and_write_config_dir(
        name: &str,
        runtime_id: &str,
        lines: &[String],
    ) -> anyhow::Result<usize> {
        let header_size = std::mem::size_of::<ConfigDirHeaderInMem>();
        let mut payload = Vec::new();
        payload.extend_from_slice(runtime_id.as_bytes());
        payload.push(b'\n');
        for l in lines {
            payload.extend_from_slice(l.as_bytes());
            payload.push(b'\n');
        }
        // Add trailing null byte like the sidecar does
        payload.push(0);
        // Size is payload only (sidecar convention: does not include header)
        let payload_size = payload.len();

        let c_name = CString::new(name.as_bytes()).unwrap();
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
        // Build contiguous buffer: header + payload
        let header = ConfigDirHeaderInMem {
            seq: AtomicU64::new(2),
            size: AtomicUsize::new(payload_size),
        };
        let header_bytes = unsafe {
            std::slice::from_raw_parts(
                (&header as *const ConfigDirHeaderInMem) as *const u8,
                header_size,
            )
        };
        let total_size = header_size + payload_size;
        let mut buf = Vec::with_capacity(total_size);
        buf.extend_from_slice(header_bytes);
        buf.extend_from_slice(&payload);

        let result = unsafe { shm_write_via_mmap(fd.as_fd(), &buf) };
        result?;
        Ok(payload_size)
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

        shm_create_and_write_config_dir(outer, runtime_id, &entries)?;

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
        assert_eq!(got[0].0, rc1_path);
        #[cfg(target_os = "macos")]
        {
            // On macOS, fstat() returns padded size (16KB min), so compare only the actual content
            assert_eq!(&got[0].1[..inner1_content.len()], inner1_content);
        }
        #[cfg(not(target_os = "macos"))]
        {
            assert_eq!(got[0].1, inner1_content);
        }
        assert_eq!(got[1].0, rc2_path);
        #[cfg(target_os = "macos")]
        {
            assert_eq!(&got[1].1[..inner2_content.len()], inner2_content);
        }
        #[cfg(not(target_os = "macos"))]
        {
            assert_eq!(got[1].1, inner2_content);
        }

        unsafe {
            let _ = libc::shm_unlink(CString::new(outer.as_bytes()).unwrap().as_ptr());
            let _ = libc::shm_unlink(CString::new(inner1.as_bytes()).unwrap().as_ptr());
            let _ = libc::shm_unlink(CString::new(inner2.as_bytes()).unwrap().as_ptr());
        }

        Ok(())
    }
}
