#[cfg(target_os = "linux")]
pub mod got_elf64;
#[cfg(target_os = "macos")]
pub mod got_macho;

use crate::profiling::profiler::Profiler;
use crate::profiling::{zend, RefCellExt, REQUEST_LOCALS};
use libc::{c_int, c_void, fstat, stat, S_IFMT, S_IFSOCK};
use rand::rngs::ThreadRng;
use rand_distr::{Distribution, Poisson};
use rustc_hash::FxHashMap;
use std::cell::RefCell;
use std::mem::MaybeUninit;
use std::os::unix::io::RawFd;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::Instant;

/// RAII guard that snapshots `errno` on creation and restores it on drop.
///
/// I/O profiling wrappers intercept libc calls and run additional logic (timing, sampling) that
/// can clobber `errno`. Callers of the original syscall expect `errno` to reflect the syscall
/// result, not our wrapper internals. Create an `ErrnoBackup` right after the original syscall
/// returns and let it live until the end of the wrapper function, its `Drop` impl will
/// transparently restore the original `errno` value.
struct ErrnoBackup {
    errno: c_int,
    location: *mut c_int,
}

impl ErrnoBackup {
    /// Snapshots the current `errno` value.
    #[inline]
    unsafe fn new() -> Self {
        #[cfg(target_os = "linux")]
        let location = libc::__errno_location();
        #[cfg(target_os = "macos")]
        let location = libc::__error();
        Self {
            errno: *location,
            location,
        }
    }
}

impl Drop for ErrnoBackup {
    /// Restores `errno` value.
    #[inline]
    fn drop(&mut self) {
        // SAFETY: points to thread's errno (cached in new), safe for writes.
        unsafe { self.location.write(self.errno) }
    }
}

pub struct GotSymbolOverwrite {
    pub symbol_name: &'static str,
    pub new_func: *mut (),
}

pub struct GotHookState<'a> {
    pub overwrites: &'a mut [GotSymbolOverwrite],
    pub restores: &'a mut Vec<GotSlotRestore>,
}

pub struct GotSlotRestore {
    pub image: usize,
    pub image_name: Box<[u8]>,
    pub slot: usize,
    pub original: usize,
    pub replacement: usize,
    #[cfg(target_os = "macos")]
    pub is_data_const: bool,
}

static GOT_SLOT_RESTORES: Mutex<Vec<GotSlotRestore>> = Mutex::new(Vec::new());

fn restore_matches_image(restore: &GotSlotRestore, image: usize, image_name: &[u8]) -> bool {
    restore.image == image && restore.image_name.as_ref() == image_name
}

fn slot_fits_range(slot: usize, start: usize, size: usize) -> bool {
    let Some(end) = start.checked_add(size) else {
        return false;
    };
    let Some(slot_end) = slot.checked_add(std::mem::size_of::<*mut ()>()) else {
        return false;
    };
    slot >= start && slot_end <= end
}

unsafe fn restore_slot_if_owned(restore: &GotSlotRestore) -> bool {
    let slot = restore.slot as *mut *mut ();
    if *slot as usize != restore.replacement {
        return false;
    }
    *slot = restore.original as *mut ();
    true
}

fn eval_poll_events(ret: i32, fds: &[libc::pollfd]) -> (bool, bool) {
    let mut has_read = false;
    let mut has_write = false;

    for pfd in fds {
        let mask = match ret {
            0 => pfd.events,
            _ if pfd.revents == 0 => continue,
            _ if pfd.revents & (libc::POLLIN | libc::POLLOUT) == 0 => pfd.events,
            _ => pfd.revents,
        };

        if (mask & libc::POLLIN) != 0 {
            has_read = true;
        }
        if (mask & libc::POLLOUT) != 0 {
            has_write = true;
        }
        if has_read && has_write {
            break;
        }
    }

    (has_read, has_write)
}

/// The `poll()` libc call has only every been observed when reading/writing to/from a socket,
/// never when reading/writing to a file. There are two known cases in PHP:
/// - the PHP stream layer (e.g. `file_get_contents("proto://url")`)
/// - the curl extension in `curl_exec()`/`curl_multi_exec()`
///
/// The `nfds` argument is usually 1, in case of a `curl_multi_exec()` call it is >= 1 and exactly
/// the number of concurrent requests. In rare cases the `nfds` argument is 0 and fds a
/// NULL-pointer. This is basically and "old trick" to ms precision sleep() and currently ignored.
unsafe extern "C" fn observed_poll(
    fds: *mut libc::pollfd,
    nfds: libc::nfds_t,
    timeout: c_int,
) -> i32 {
    let start = Instant::now();
    let ret = libc::poll(fds, nfds, timeout);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    if ret >= 0 && nfds > 0 && !fds.is_null() {
        let duration_nanos = duration.as_nanos() as u64;
        let slice = unsafe { std::slice::from_raw_parts(fds, nfds as usize) };
        let (has_read, has_write) = eval_poll_events(ret, slice);

        if has_read
            && SOCKET_READ_TIME_PROFILING_STATS
                .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
        {
            collect_socket_read_time(duration_nanos);
        }
        if has_write
            && SOCKET_WRITE_TIME_PROFILING_STATS
                .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
        {
            collect_socket_write_time(duration_nanos);
        }
    }

    ret
}

unsafe extern "C" fn observed_recv(
    socket: c_int,
    buf: *mut c_void,
    length: usize,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = libc::recv(socket, buf, length, flags);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if SOCKET_READ_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos))
    {
        collect_socket_read_time(duration_nanos);
    }
    if len > 0 {
        let len_u64 = len as u64;
        if SOCKET_READ_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
            collect_socket_read_size(len_u64);
        }
    }

    len
}

unsafe extern "C" fn observed_recvmsg(
    socket: c_int,
    msg: *mut libc::msghdr,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = libc::recvmsg(socket, msg, flags);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if SOCKET_READ_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos))
    {
        collect_socket_read_time(duration_nanos);
    }
    if len > 0 {
        let len_u64 = len as u64;
        if SOCKET_READ_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
            collect_socket_read_size(len_u64);
        }
    }

    len
}

unsafe extern "C" fn observed_recvfrom(
    socket: c_int,
    buf: *mut c_void,
    length: usize,
    flags: c_int,
    address: *mut libc::sockaddr,
    address_len: *mut libc::socklen_t,
) -> isize {
    let start = Instant::now();
    let len = libc::recvfrom(socket, buf, length, flags, address, address_len);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if SOCKET_READ_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos))
    {
        collect_socket_read_time(duration_nanos);
    }
    if len > 0 {
        let len_u64 = len as u64;
        if SOCKET_READ_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
            collect_socket_read_size(len_u64);
        }
    }

    len
}

unsafe extern "C" fn observed_send(
    socket: c_int,
    buf: *const c_void,
    length: usize,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = libc::send(socket, buf, length, flags);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if SOCKET_WRITE_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos))
    {
        collect_socket_write_time(duration_nanos);
    }
    if len > 0 {
        let len_u64 = len as u64;
        if SOCKET_WRITE_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
            collect_socket_write_size(len_u64);
        }
    }

    len
}

unsafe extern "C" fn observed_sendmsg(
    socket: c_int,
    msg: *const libc::msghdr,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = libc::sendmsg(socket, msg, flags);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if SOCKET_WRITE_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos))
    {
        collect_socket_write_time(duration_nanos);
    }
    if len > 0 {
        let len_u64 = len as u64;
        if SOCKET_WRITE_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
            collect_socket_write_size(len_u64);
        }
    }

    len
}

unsafe extern "C" fn observed_fwrite(
    ptr: *const c_void,
    size: usize,
    nobj: usize,
    stream: *mut libc::FILE,
) -> usize {
    let start = Instant::now();
    let len = libc::fwrite(ptr, size, nobj, stream);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if FILE_WRITE_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos)) {
        collect_file_write_time(duration_nanos);
    }
    let bytes = (len as u64).saturating_mul(size as u64);
    if bytes > 0
        && FILE_WRITE_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(bytes))
    {
        collect_file_write_size(bytes);
    }

    len
}

unsafe extern "C" fn observed_write(fd: c_int, buf: *const c_void, count: usize) -> isize {
    let start = Instant::now();
    let len = libc::write(fd, buf, count);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if fd_is_socket(fd) {
        if SOCKET_WRITE_TIME_PROFILING_STATS
            .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
        {
            collect_socket_write_time(duration_nanos);
        }
        if len > 0 {
            let len_u64 = len as u64;
            if SOCKET_WRITE_SIZE_PROFILING_STATS
                .borrow_mut_or_false(|io| io.should_collect(len_u64))
            {
                collect_socket_write_size(len_u64);
            }
        }
    } else {
        if FILE_WRITE_TIME_PROFILING_STATS
            .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
        {
            collect_file_write_time(duration_nanos);
        }
        if len > 0 {
            let len_u64 = len as u64;
            if FILE_WRITE_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64))
            {
                collect_file_write_size(len_u64);
            }
        }
    }

    len
}

// So far there seems to be only one situation where a file is read using `fread()` instead of
// `read()` in PHP and that is when compiling a PHP file, triggered by it being the start file or a
// userland call to `include()`/`require()` functions.
unsafe extern "C" fn observed_fread(
    ptr: *mut c_void,
    size: usize,
    nobj: usize,
    stream: *mut libc::FILE,
) -> usize {
    let start = Instant::now();
    let len = libc::fread(ptr, size, nobj, stream);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if FILE_READ_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos)) {
        collect_file_read_time(duration_nanos);
    }
    let bytes = (len as u64).saturating_mul(size as u64);
    if bytes > 0
        && FILE_READ_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(bytes))
    {
        collect_file_read_size(bytes);
    }

    len
}

unsafe extern "C" fn observed_read(fd: c_int, buf: *mut c_void, count: usize) -> isize {
    let start = Instant::now();
    let len = libc::read(fd, buf, count);
    let _errno_backup = ErrnoBackup::new();
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if fd_is_socket(fd) {
        if SOCKET_READ_TIME_PROFILING_STATS
            .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
        {
            collect_socket_read_time(duration_nanos);
        }
        if len > 0 {
            let len_u64 = len as u64;
            if SOCKET_READ_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64))
            {
                collect_socket_read_size(len_u64);
            }
        }
    } else {
        if FILE_READ_TIME_PROFILING_STATS
            .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
        {
            collect_file_read_time(duration_nanos);
        }
        if len > 0 {
            let len_u64 = len as u64;
            if FILE_READ_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
                collect_file_read_size(len_u64);
            }
        }
    }

    len
}

/// The sole purpose of this function is to remove the `fd` from the `FD_CACHE`
unsafe extern "C" fn observed_close(fd: i32) -> i32 {
    let ret = libc::close(fd);
    let _errno_backup = ErrnoBackup::new();
    let cache = FD_CACHE.get_or_init(|| Mutex::new(FxHashMap::default()));
    let mut cache = cache.lock().unwrap();
    cache.remove(&fd);
    ret
}

/// "Is socket"-cache for `read()`/`write()` calls
static FD_CACHE: OnceLock<Mutex<FxHashMap<RawFd, bool>>> = OnceLock::new();

/// Returns `true` if the given `fd` is a socket. It could also be a regular file, directory, pipe,
/// character or block device, in which case we declare this as file I/O and not socket I/O.
fn fd_is_socket(fd: RawFd) -> bool {
    let cache = FD_CACHE.get_or_init(|| Mutex::new(FxHashMap::default()));
    if let Some(&is_socket) = cache.lock().unwrap().get(&fd) {
        return is_socket;
    }

    let mut statbuf = MaybeUninit::<stat>::uninit();
    let is_socket = unsafe {
        if fstat(fd, statbuf.as_mut_ptr()) == -1 {
            false // Assume it's not a socket if fstat fails
        } else {
            let statbuf = statbuf.assume_init();
            (statbuf.st_mode & S_IFMT) == S_IFSOCK
        }
    };

    let mut cache = cache.lock().unwrap();
    cache.insert(fd, is_socket);

    is_socket
}

/// Take a sample every 1 second of read I/O
/// Will be initialized on first RINIT and is controlled by a INI_SYSTEM, so we do not need a
/// thread local for the profiling interval.
pub static SOCKET_READ_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(10).as_nanos() as u64);
pub static SOCKET_WRITE_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(10).as_nanos() as u64);
pub static FILE_READ_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(10).as_nanos() as u64);
pub static FILE_WRITE_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(10).as_nanos() as u64);
pub static SOCKET_READ_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1024 * 100);
pub static SOCKET_WRITE_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1024 * 100);
pub static FILE_READ_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1024 * 100);
pub static FILE_WRITE_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1024 * 100);

#[cold]
fn collect_socket_read_time(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_read_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

#[cold]
fn collect_socket_write_time(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_write_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

#[cold]
fn collect_file_read_time(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_read_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

#[cold]
fn collect_file_write_time(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_write_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

#[cold]
fn collect_socket_read_size(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_read_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

#[cold]
fn collect_socket_write_size(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_write_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

#[cold]
fn collect_file_read_size(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_read_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

#[cold]
fn collect_file_write_size(value: u64) {
    if let Some(profiler) = Profiler::get() {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_write_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct IOProfilingStats {
    next_sample: u64,
    poisson: Poisson<f64>,
    rng: ThreadRng,
}

impl IOProfilingStats {
    fn new(lambda: f64) -> Self {
        // Safety: this will only error if lambda <= 0
        let poisson = Poisson::new(lambda).unwrap();
        let mut stats = IOProfilingStats {
            next_sample: 0,
            poisson,
            rng: rand::rng(),
        };
        stats.next_sampling_interval();
        stats
    }

    fn next_sampling_interval(&mut self) {
        self.next_sample = self.poisson.sample(&mut self.rng) as u64;
    }

    fn should_collect(&mut self, value: u64) -> bool {
        let zend_thread =
            REQUEST_LOCALS.borrow_or_false(|locals| !locals.vm_interrupt_addr.is_null());
        if !zend_thread {
            // `curl_exec()` for example will spawn a new thread for name resolution. GOT hooking
            // follows threads and as such we might sample from another (non PHP) thread even in a
            // NTS build of PHP. We have observed crashes for these cases, so instead of crashing
            // (or risking a crash) we refrain from collection I/O.
            return false;
        }
        if let Some(next_sample) = self.next_sample.checked_sub(value) {
            self.next_sample = next_sample;
            return false;
        }
        self.next_sampling_interval();
        true
    }
}

thread_local! {
    static SOCKET_READ_TIME_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_READ_TIME_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
    static SOCKET_WRITE_TIME_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
    static FILE_READ_TIME_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_READ_TIME_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
    static FILE_WRITE_TIME_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
    static SOCKET_READ_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_READ_SIZE_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
    static SOCKET_WRITE_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
    static FILE_READ_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_READ_SIZE_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
    static FILE_WRITE_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64,
        )
    );
}

pub fn io_prof_first_rinit() {
    let io_profiling =
        REQUEST_LOCALS.borrow_or_false(|locals| locals.system_settings().profiling_io_enabled);

    if io_profiling {
        unsafe {
            let mut overwrites = vec![
                GotSymbolOverwrite {
                    symbol_name: "recv",
                    new_func: observed_recv as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "recvmsg",
                    new_func: observed_recvmsg as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "recvfrom",
                    new_func: observed_recvfrom as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "send",
                    new_func: observed_send as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "sendmsg",
                    new_func: observed_sendmsg as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "write",
                    new_func: observed_write as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "read",
                    new_func: observed_read as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "fwrite",
                    new_func: observed_fwrite as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "fread",
                    new_func: observed_fread as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "close",
                    new_func: observed_close as *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "poll",
                    new_func: observed_poll as *mut (),
                },
            ];
            let mut restores = GOT_SLOT_RESTORES.lock().unwrap();
            let mut state = GotHookState {
                overwrites: &mut overwrites,
                restores: &mut restores,
            };

            #[cfg(target_os = "linux")]
            libc::dl_iterate_phdr(
                Some(got_elf64::callback),
                &mut state as *mut _ as *mut libc::c_void,
            );

            #[cfg(target_os = "macos")]
            got_macho::rebind_symbols(&mut state);
        };
    }
}

pub fn io_prof_mshutdown() -> bool {
    let mut restores = GOT_SLOT_RESTORES.lock().unwrap();
    unsafe {
        #[cfg(target_os = "linux")]
        {
            got_elf64::restore_symbols(&mut restores)
        }

        #[cfg(target_os = "macos")]
        {
            got_macho::restore_symbols(&mut restores)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::{
        eval_poll_events, restore_matches_image, slot_fits_range, ErrnoBackup, GotSlotRestore,
    };
    use static_assertions::assert_not_impl_any;

    assert_not_impl_any!(ErrnoBackup: Send, Sync);

    #[test]
    fn restore_requires_same_image_and_mapped_slot() {
        let restore = GotSlotRestore {
            image: 0x1000,
            image_name: Box::from(&b"image"[..]),
            slot: 0x1800,
            original: 0,
            replacement: 1,
            #[cfg(target_os = "macos")]
            is_data_const: false,
        };

        assert!(restore_matches_image(&restore, 0x1000, b"image"));
        assert!(!restore_matches_image(&restore, 0x2000, b"image"));
        assert!(!restore_matches_image(&restore, 0x1000, b"replacement"));
        assert!(slot_fits_range(0x1800, 0x1000, 0x1000));
        assert!(!slot_fits_range(0x2000, 0x1000, 0x1000));
        assert!(!slot_fits_range(usize::MAX, 0x1000, 0x1000));
    }

    #[test]
    fn no_hooks_are_safe_to_unload() {
        let mut restores = Vec::new();
        #[cfg(target_os = "linux")]
        assert!(unsafe { super::got_elf64::restore_symbols(&mut restores) });
        #[cfg(target_os = "macos")]
        assert!(unsafe { super::got_macho::restore_symbols(&mut restores) });
    }

    #[test]
    fn test_eval_poll_events_ready() {
        let fds = [libc::pollfd {
            fd: 3,
            events: libc::POLLIN | libc::POLLOUT,
            revents: libc::POLLIN,
        }];
        assert_eq!(eval_poll_events(1, &fds), (true, false));

        let fds_both = [libc::pollfd {
            fd: 3,
            events: libc::POLLIN | libc::POLLOUT,
            revents: libc::POLLIN | libc::POLLOUT,
        }];
        assert_eq!(eval_poll_events(1, &fds_both), (true, true));
    }

    #[test]
    fn test_eval_poll_events_timeout() {
        let fds = [libc::pollfd {
            fd: 3,
            events: libc::POLLIN,
            revents: 0,
        }];
        assert_eq!(eval_poll_events(0, &fds), (true, false));
    }

    #[test]
    fn test_eval_poll_events_hangup() {
        let fds = [libc::pollfd {
            fd: 3,
            events: libc::POLLOUT,
            revents: libc::POLLHUP,
        }];
        assert_eq!(eval_poll_events(1, &fds), (false, true));
    }

    #[test]
    fn test_eval_poll_events_multiple_fds() {
        let fds = [
            libc::pollfd {
                fd: 3,
                events: libc::POLLIN,
                revents: 0,
            },
            libc::pollfd {
                fd: 4,
                events: libc::POLLOUT,
                revents: libc::POLLOUT,
            },
        ];
        assert_eq!(eval_poll_events(1, &fds), (false, true));
    }

    #[test]
    fn test_eval_poll_events_empty() {
        assert_eq!(eval_poll_events(0, &[]), (false, false));
        assert_eq!(eval_poll_events(1, &[]), (false, false));
    }
}
