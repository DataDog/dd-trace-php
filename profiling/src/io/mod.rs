pub mod got;

use crate::profiling::Profiler;
use crate::{zend, RefCellExt, REQUEST_LOCALS};
use ahash::{HashMap, HashMapExt};
use got::GotSymbolOverwrite;
use libc::{c_int, c_void, fstat, stat, S_IFMT, S_IFSOCK};
use rand::rngs::ThreadRng;
use rand_distr::{Distribution, Poisson};
use std::cell::RefCell;
use std::mem::MaybeUninit;
use std::os::unix::io::RawFd;
use std::ptr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::Instant;

static mut ORIG_POLL: unsafe extern "C" fn(*mut libc::pollfd, u64, c_int) -> i32 = libc::poll;
/// The `poll()` libc call has only every been observed when reading/writing to/from a socket,
/// never when reading/writing to a file. There is two known cases in PHP:
/// - the PHP stream layer (e.g. `file_get_contents("proto://url")`)
/// - the curl extension in `curl_exec()`/`curl_multi_exec()`
///
/// The `nfds` argument is usually 1, in case of a `curl_multi_exec()` call it is >= 1 and exactly
/// the number of concurrent requests. In rare cases the `nfds` argument is 0 and fds a
/// NULL-pointer. This is basically and "old trick" to ms precision sleep() and currently ignored.
unsafe extern "C" fn observed_poll(fds: *mut libc::pollfd, nfds: u64, timeout: c_int) -> i32 {
    let start = Instant::now();
    let ret = ORIG_POLL(fds, nfds, timeout);
    let duration = start.elapsed();

    if !fds.is_null() {
        let duration_nanos = duration.as_nanos() as u64;
        if (*fds).revents & 1 == 1 {
            // requested events contains reading
            if SOCKET_READ_TIME_PROFILING_STATS
                .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
            {
                collect_socket_read_time(duration_nanos);
            }
        } else if (*fds).revents & 4 == 4 {
            // requested events contains writing
            if SOCKET_WRITE_TIME_PROFILING_STATS
                .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
            {
                collect_socket_write_time(duration_nanos);
            }
        } else if (*fds).events & 1 == 1 {
            // socket became readable
            if SOCKET_READ_TIME_PROFILING_STATS
                .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
            {
                collect_socket_read_time(duration_nanos);
            }
        } else if (*fds).events & 4 == 4 {
            // socket became writeable
            if SOCKET_WRITE_TIME_PROFILING_STATS
                .borrow_mut_or_false(|io| io.should_collect(duration_nanos))
            {
                collect_socket_write_time(duration_nanos);
            }
        }
    }

    ret
}

static mut ORIG_RECV: unsafe extern "C" fn(c_int, *mut c_void, usize, c_int) -> isize = libc::recv;

unsafe extern "C" fn observed_recv(
    socket: c_int,
    buf: *mut c_void,
    length: usize,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = ORIG_RECV(socket, buf, length, flags);
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

static mut ORIG_RECVMSG: unsafe extern "C" fn(c_int, *mut libc::msghdr, c_int) -> isize =
    libc::recvmsg;

unsafe extern "C" fn observed_recvmsg(
    socket: c_int,
    msg: *mut libc::msghdr,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = ORIG_RECVMSG(socket, msg, flags);
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

static mut ORIG_RECVFROM: unsafe extern "C" fn(
    c_int,
    *mut c_void,
    usize,
    c_int,
    *mut libc::sockaddr,
    *mut libc::socklen_t,
) -> isize = libc::recvfrom;

unsafe extern "C" fn observed_recvfrom(
    socket: c_int,
    buf: *mut c_void,
    length: usize,
    flags: c_int,
    address: *mut libc::sockaddr,
    address_len: *mut libc::socklen_t,
) -> isize {
    let start = Instant::now();
    let len = ORIG_RECVFROM(socket, buf, length, flags, address, address_len);
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

static mut ORIG_SEND: unsafe extern "C" fn(c_int, *const c_void, usize, c_int) -> isize =
    libc::send;
unsafe extern "C" fn observed_send(
    socket: c_int,
    buf: *const c_void,
    length: usize,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = ORIG_SEND(socket, buf, length, flags);
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

static mut ORIG_SENDMSG: unsafe extern "C" fn(c_int, *const libc::msghdr, c_int) -> isize =
    libc::sendmsg;
unsafe extern "C" fn observed_sendmsg(
    socket: c_int,
    msg: *const libc::msghdr,
    flags: c_int,
) -> isize {
    let start = Instant::now();
    let len = ORIG_SENDMSG(socket, msg, flags);
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

static mut ORIG_FWRITE: unsafe extern "C" fn(
    *const c_void,
    usize,
    usize,
    *mut libc::FILE,
) -> usize = libc::fwrite;
unsafe extern "C" fn observed_fwrite(
    ptr: *const c_void,
    size: usize,
    nobj: usize,
    stream: *mut libc::FILE,
) -> usize {
    let start = Instant::now();
    let len = ORIG_FWRITE(ptr, size, nobj, stream);
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if FILE_WRITE_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos)) {
        collect_file_write_time(duration_nanos);
    }
    if len > 0 {
        let len_u64 = len as u64;
        if FILE_WRITE_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
            collect_file_write_size(len_u64);
        }
    }

    len
}

static mut ORIG_WRITE: unsafe extern "C" fn(c_int, *const c_void, usize) -> isize = libc::write;
unsafe extern "C" fn observed_write(fd: c_int, buf: *const c_void, count: usize) -> isize {
    let start = Instant::now();
    let len = ORIG_WRITE(fd, buf, count);
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

static mut ORIG_FREAD: unsafe extern "C" fn(*mut c_void, usize, usize, *mut libc::FILE) -> usize =
    libc::fread;
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
    let len = ORIG_FREAD(ptr, size, nobj, stream);
    let duration = start.elapsed();

    let duration_nanos = duration.as_nanos() as u64;
    if FILE_READ_TIME_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(duration_nanos)) {
        collect_file_read_time(duration_nanos);
    }
    if len > 0 {
        let len_u64 = len as u64;
        if FILE_READ_SIZE_PROFILING_STATS.borrow_mut_or_false(|io| io.should_collect(len_u64)) {
            collect_file_read_size(len_u64);
        }
    }

    len
}

static mut ORIG_READ: unsafe extern "C" fn(c_int, *mut c_void, usize) -> isize = libc::read;
unsafe extern "C" fn observed_read(fd: c_int, buf: *mut c_void, count: usize) -> isize {
    let start = Instant::now();
    let len = ORIG_READ(fd, buf, count);
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

static mut ORIG_CLOSE: unsafe extern "C" fn(i32) -> i32 = libc::close;
/// The sole purpose of this function is to remove the `fd` from the `FD_CACHE`
unsafe extern "C" fn observed_close(fd: i32) -> i32 {
    let ret = ORIG_CLOSE(fd);
    let cache = FD_CACHE.get_or_init(|| Mutex::new(HashMap::new()));
    let mut cache = cache.lock().unwrap();
    cache.remove(&fd);
    ret
}

/// "Is socket"-cache for `read()`/`write()` calls
static FD_CACHE: OnceLock<Mutex<HashMap<RawFd, bool>>> = OnceLock::new();

/// Returns `true` if the given `fd` is a socket. It could also be a regular file, directory, pipe,
/// character or block device, in which case we declare this as file I/O and not socket I/O.
fn fd_is_socket(fd: RawFd) -> bool {
    let cache = FD_CACHE.get_or_init(|| Mutex::new(HashMap::new()));
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
            rng: rand::thread_rng(),
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
            SOCKET_READ_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
        )
    );
    static SOCKET_WRITE_TIME_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
        )
    );
    static FILE_READ_TIME_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_READ_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
        )
    );
    static FILE_WRITE_TIME_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
        )
    );
    static SOCKET_READ_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_READ_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
        )
    );
    static SOCKET_WRITE_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
        )
    );
    static FILE_READ_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_READ_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
        )
    );
    static FILE_WRITE_SIZE_PROFILING_STATS: RefCell<IOProfilingStats> = RefCell::new(
        IOProfilingStats::new(
            FILE_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
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
                    orig_func: ptr::addr_of_mut!(ORIG_RECV) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "recvmsg",
                    new_func: observed_recvmsg as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_RECVMSG) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "recvfrom",
                    new_func: observed_recvfrom as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_RECVFROM) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "send",
                    new_func: observed_send as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_SEND) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "sendmsg",
                    new_func: observed_sendmsg as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_SENDMSG) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "write",
                    new_func: observed_write as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_WRITE) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "read",
                    new_func: observed_read as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_READ) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "fwrite",
                    new_func: observed_fwrite as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_FWRITE) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "fread",
                    new_func: observed_fread as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_FREAD) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "close",
                    new_func: observed_close as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_CLOSE) as *mut _ as *mut *mut (),
                },
                GotSymbolOverwrite {
                    symbol_name: "poll",
                    new_func: observed_poll as *mut (),
                    orig_func: ptr::addr_of_mut!(ORIG_POLL) as *mut _ as *mut *mut (),
                },
            ];
            libc::dl_iterate_phdr(
                Some(got::callback),
                &mut overwrites as *mut _ as *mut libc::c_void,
            );
        };
    }
}
