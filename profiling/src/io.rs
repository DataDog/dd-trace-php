use crate::bindings::{
    Elf64_Dyn, Elf64_Rela, Elf64_Sym, Elf64_Xword, DT_JMPREL, DT_NULL, DT_PLTRELSZ, DT_STRTAB,
    DT_SYMTAB, PT_DYNAMIC, R_AARCH64_JUMP_SLOT,
};
use crate::profiling::Profiler;
use crate::zend;
use crate::REQUEST_LOCALS;
use libc::{c_char, c_int, c_void, dl_phdr_info};
use log::{error, trace};
use std::ffi::CStr;
use std::ptr;
use std::time::Instant;
//use std::time::SystemTime;
//use std::time::UNIX_EPOCH;
use rand::rngs::ThreadRng;
use std::cell::RefCell;
use std::sync::atomic::AtomicU64;
use std::sync::atomic::Ordering;

use rand_distr::{Distribution, Poisson};

fn elf64_r_type(info: Elf64_Xword) -> u32 {
    (info & 0xffffffff) as u32
}

fn elf64_r_sym(info: Elf64_Xword) -> u32 {
    (info >> 32) as u32
}

unsafe fn override_got_entry(info: *mut dl_phdr_info, overwrites: *mut Vec<GotSymbolOverwrite>) {
    let phdr = (*info).dlpi_phdr;

    let mut dyn_ptr: *const Elf64_Dyn = ptr::null();
    for i in 0..(*info).dlpi_phnum {
        let phdr_i = phdr.offset(i as isize);
        if (*phdr_i).p_type == PT_DYNAMIC {
            dyn_ptr = ((*info).dlpi_addr as usize + (*phdr_i).p_vaddr as usize) as *const Elf64_Dyn;
            break;
        }
    }
    if dyn_ptr.is_null() {
        trace!("Failed to locate dynamic section");
        return;
    }

    let mut rel_plt: *mut Elf64_Rela = ptr::null_mut();
    let mut rel_plt_size: usize = 0;
    let mut symtab: *mut Elf64_Sym = ptr::null_mut();
    let mut strtab: *const c_char = ptr::null();

    let mut dyn_iter = dyn_ptr;
    loop {
        let d_tag = (*dyn_iter).d_tag as u32;
        if d_tag == DT_NULL {
            break;
        }
        match d_tag {
            DT_JMPREL => {
                if ((*dyn_iter).d_un.d_ptr as usize) < ((*info).dlpi_addr as usize) {
                    rel_plt = ((*info).dlpi_addr as usize + (*dyn_iter).d_un.d_ptr as usize)
                        as *mut Elf64_Rela;
                } else {
                    rel_plt = (*dyn_iter).d_un.d_ptr as *mut Elf64_Rela;
                }
            }
            DT_PLTRELSZ => {
                rel_plt_size = (*dyn_iter).d_un.d_val as usize;
            }
            DT_SYMTAB => {
                if ((*dyn_iter).d_un.d_ptr as usize) < ((*info).dlpi_addr as usize) {
                    symtab = ((*info).dlpi_addr as usize + (*dyn_iter).d_un.d_ptr as usize)
                        as *mut Elf64_Sym;
                } else {
                    symtab = (*dyn_iter).d_un.d_ptr as *mut Elf64_Sym;
                }
            }
            DT_STRTAB => {
                if ((*dyn_iter).d_un.d_ptr as usize) < ((*info).dlpi_addr as usize) {
                    strtab = ((*info).dlpi_addr as usize + (*dyn_iter).d_un.d_ptr as usize)
                        as *const c_char;
                } else {
                    strtab = (*dyn_iter).d_un.d_ptr as *const c_char;
                }
            }
            _ => {}
        }
        dyn_iter = dyn_iter.offset(1);
    }

    if rel_plt.is_null() || rel_plt_size == 0 || symtab.is_null() || strtab.is_null() {
        trace!("Failed to locate required ELF sections");
        return;
    }

    let num_relocs = rel_plt_size / std::mem::size_of::<Elf64_Rela>();
    for overwrite in &mut *overwrites {
        for i in 0..num_relocs {
            let rel = rel_plt.add(i);
            let r_type = elf64_r_type((*rel).r_info);
            if r_type != R_AARCH64_JUMP_SLOT {
                continue;
            }
            let sym_index = elf64_r_sym((*rel).r_info) as usize;
            let sym = symtab.add(sym_index);
            let name_offset = (*sym).st_name as isize;
            let name_ptr = strtab.offset(name_offset);
            let name = CStr::from_ptr(name_ptr).to_str().unwrap_or("");
            if name == overwrite.symbol_name {
                let got_entry =
                    ((*info).dlpi_addr as usize + (*rel).r_offset as usize) as *mut *mut ();

                let page_size = libc::sysconf(libc::_SC_PAGESIZE) as usize;
                let aligned_addr = (got_entry as usize) & !(page_size - 1);
                if libc::mprotect(
                    aligned_addr as *mut c_void,
                    page_size,
                    libc::PROT_READ | libc::PROT_WRITE,
                ) != 0
                {
                    let err = *libc::__errno_location();
                    trace!("mprotect failed: {}", err);
                    return;
                }

                trace!(
                    "Overriding GOT entry for {} at offset {:?} (abs: {:p}) pointing to {:p} (orig function at {:p})",
                    overwrite.symbol_name,
                    (*rel).r_offset,
                    got_entry,
                    *got_entry,
                    *overwrite.orig_func
                );

                // This works for musl based linux distros, but not for libc once
                *overwrite.orig_func = libc::dlsym(libc::RTLD_NEXT, name_ptr) as *mut ();
                if (*overwrite.orig_func).is_null() {
                    // libc linux fallback
                    *overwrite.orig_func = *got_entry;
                }
                *got_entry = overwrite.new_func;

                trace!(
                    "Overrode GOT entry for {} at offset {:?} (abs: {:p}) pointing to {:p} (orig function at {:p})",
                    overwrite.symbol_name,
                    (*rel).r_offset,
                    got_entry,
                    *got_entry,
                    *overwrite.orig_func
                );
                continue;
            }
        }
    }

    //trace!(
    //    "Failed to find symbol in relocation entries: {}",
    //    CStr::from_ptr(symbol_name).to_string_lossy()
    //);
}

unsafe extern "C" fn callback(info: *mut dl_phdr_info, _size: usize, data: *mut c_void) -> c_int {
    let overwrites = &mut *(data as *mut Vec<GotSymbolOverwrite>);

    // detect myself ...
    let mut my_info: libc::Dl_info = std::mem::zeroed();
    if libc::dladdr(callback as *const c_void, &mut my_info) == 0 {
        error!("Did not find my owne `dladdr` and therefore can't hook into the GOT.");
        return 0;
    }
    let my_base_addr = my_info.dli_fbase as usize;
    let module_base_addr = (*info).dlpi_addr as usize;
    if module_base_addr == my_base_addr {
        // "this" lib is actually me: skipping GOT hooking for myself
        return 0;
    }

    let name = if (*info).dlpi_name.is_null() || *(*info).dlpi_name == 0 {
        "[Executable]"
    } else {
        CStr::from_ptr((*info).dlpi_name)
            .to_str()
            .unwrap_or("[Unknown]")
    };

    //libc::raise(libc::SIGTRAP);

    // I guess if we try to hook into GOT from `linux-vdso` or `ld-linux` our best outcome will be
    // that nothing happens, most likely we'll crash
    if name.contains("linux-vdso") || name.contains("ld-linux") {
        return 0;
    }

    // `curl_exec()` will spawn a new thread for name resolution. GOT hooking follows threads and
    // as such we might sample from another thread even in a NTS build of PHP, which we should not.
    // So instead of crashing (or risking a crash) we currently refrain from collection I/O from
    // name resolution.
    //if name.contains("resolve") {
    //    return 0;
    //}

    trace!(
        "ELF headers at: 0x{:x}, Library: {}",
        (*info).dlpi_addr,
        name
    );

    override_got_entry(info, overwrites);

    0
}

struct GotSymbolOverwrite {
    symbol_name: &'static str,
    new_func: *mut (),
    orig_func: *mut *mut (),
}

static mut ORIG_POLL: unsafe extern "C" fn(*mut libc::pollfd, u64, c_int) -> i32 = libc::poll;
unsafe extern "C" fn observed_poll(fds: *mut libc::pollfd, nfds: u64, timeout: c_int) -> i32 {
    ORIG_POLL(fds, nfds, timeout)
    //let start = Instant::now();
    //let fds = ORIG_POLL(fds, nfds, timeout);
    //let duration = start.elapsed();
    //println!(
    //    "Observed poll of with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    //let now = SystemTime::now().duration_since(UNIX_EPOCH);
    //if now.is_err() {
    //    return fds;
    //}

    //IO_READ_PROFILING_STATS.with(|cell| {
    //    let mut io = cell.borrow_mut();
    //    io.track(duration.as_nanos() as u64)
    //});

    //fds
}

static mut ORIG_SELECT: unsafe extern "C" fn(
    c_int,
    *mut libc::fd_set,
    *mut libc::fd_set,
    *mut libc::fd_set,
    *mut libc::timeval,
) -> i32 = libc::select;
unsafe extern "C" fn observed_select(
    nfds: c_int,
    readfds: *mut libc::fd_set,
    writefds: *mut libc::fd_set,
    exceptfds: *mut libc::fd_set,
    timeout: *mut libc::timeval,
) -> i32 {
    ORIG_SELECT(nfds, readfds, writefds, exceptfds, timeout)
    //let start = Instant::now();
    //let fds = ORIG_SELECT(nfds, readfds, writefds, exceptfds, timeout);
    //let duration = start.elapsed();
    //println!(
    //    "Observed select of with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    //let now = SystemTime::now().duration_since(UNIX_EPOCH);
    //if now.is_err() {
    //    return fds;
    //}

    //IO_READ_PROFILING_STATS.with(|cell| {
    //    let mut io = cell.borrow_mut();
    //    io.track(duration.as_nanos() as u64)
    //});

    //fds
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
    //println!(
    //    "Observed recv of {len} bytes ({length} bytes buffer) with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    //let now = SystemTime::now().duration_since(UNIX_EPOCH);
    //if now.is_err() {
    //    return len;
    //}

    let io_time_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_time_enabled)
            .unwrap_or(false)
    });
    let io_size_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_size_enabled)
            .unwrap_or(false)
    });

    if io_time_profiling {
        SOCKET_READ_TIME_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(duration.as_nanos() as u64)
        });
    }

    if io_size_profiling {
        SOCKET_READ_SIZE_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(len as u64)
        });
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
    //libc::raise(libc::SIGTRAP);
    let start = Instant::now();
    let len = ORIG_SEND(socket, buf, length, flags);
    let duration = start.elapsed();
    //println!(
    //    "Observed send of {len} bytes with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    //let now = SystemTime::now().duration_since(UNIX_EPOCH);
    //if now.is_err() {
    //    return len;
    //}

    let io_time_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_time_enabled)
            .unwrap_or(false)
    });
    let io_size_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_size_enabled)
            .unwrap_or(false)
    });

    if io_time_profiling {
        SOCKET_WRITE_TIME_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(duration.as_nanos() as u64)
        });
    }

    if io_size_profiling {
        SOCKET_WRITE_SIZE_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(len as u64)
        });
    }

    len
}

static mut ORIG_WRITE: unsafe extern "C" fn(c_int, *const c_void, usize) -> isize = libc::write;
unsafe extern "C" fn observed_write(fd: c_int, buf: *const c_void, count: usize) -> isize {
    let start = Instant::now();
    let len = ORIG_WRITE(fd, buf, count);
    let duration = start.elapsed();
    //println!(
    //    "Observed write of {len} bytes with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    //let now = SystemTime::now().duration_since(UNIX_EPOCH);
    //if now.is_err() {
    //    return len;
    //}

    let io_time_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_time_enabled)
            .unwrap_or(false)
    });
    let io_size_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_size_enabled)
            .unwrap_or(false)
    });

    if io_time_profiling {
        FILE_WRITE_TIME_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(duration.as_nanos() as u64)
        });
    }

    if io_size_profiling {
        FILE_WRITE_SIZE_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(len as u64)
        });
    }

    len
}

static mut ORIG_READ: unsafe extern "C" fn(c_int, *mut c_void, usize) -> isize = libc::read;
unsafe extern "C" fn observed_read(fd: c_int, buf: *mut c_void, count: usize) -> isize {
    let start = Instant::now();
    let len = ORIG_READ(fd, buf, count);
    let duration = start.elapsed();
    //println!(
    //    "Observed read of {len} bytes ({count} bytes buffer) with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    //let now = SystemTime::now().duration_since(UNIX_EPOCH);
    //if now.is_err() {
    //    return len;
    //}

    let io_time_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_time_enabled)
            .unwrap_or(false)
    });
    let io_size_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_io_size_enabled)
            .unwrap_or(false)
    });

    if io_time_profiling {
        FILE_READ_TIME_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(duration.as_nanos() as u64)
        });
    }

    if io_size_profiling {
        FILE_READ_SIZE_PROFILING_STATS.with(|cell| {
            let mut io = cell.borrow_mut();
            io.track(len as u64)
        });
    }

    len
}

static mut ORIG_FLOCK: unsafe extern "C" fn(c_int, c_int) -> c_int = libc::flock;
unsafe extern "C" fn observed_flock(fd: c_int, op: c_int) -> c_int {
    ORIG_FLOCK(fd, op)
}

/// Take a sample every 1 second of read I/O
/// Will be initialized on first RINIT and is controlled by a INI_SYSTEM, so we do not need a
/// thread local for the profiling interval.
pub static SOCKET_READ_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(1).as_nanos() as u64);
pub static SOCKET_WRITE_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(1).as_nanos() as u64);
pub static FILE_READ_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(1).as_nanos() as u64);
pub static FILE_WRITE_TIME_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(std::time::Duration::from_millis(1).as_nanos() as u64);
pub static SOCKET_READ_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1);
pub static SOCKET_WRITE_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1);
pub static FILE_READ_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1);
pub static FILE_WRITE_SIZE_PROFILING_INTERVAL: AtomicU64 = AtomicU64::new(1);

pub trait IOCollector {
    fn collect(&self, profiler: &Profiler, value: u64);
}

pub struct SocketReadTimeCollector;
impl IOCollector for SocketReadTimeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_read_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct SocketWriteTimeCollector;
impl IOCollector for SocketWriteTimeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_write_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct FileReadTimeCollector;
impl IOCollector for FileReadTimeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_read_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct FileWriteTimeCollector;
impl IOCollector for FileWriteTimeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_write_time(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct SocketReadSizeCollector;
impl IOCollector for SocketReadSizeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_read_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct SocketWriteSizeCollector;
impl IOCollector for SocketWriteSizeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_socket_write_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct FileReadSizeCollector;
impl IOCollector for FileReadSizeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_read_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct FileWriteSizeCollector;
impl IOCollector for FileWriteSizeCollector {
    fn collect(&self, profiler: &Profiler, value: u64) {
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_file_write_size(
                zend::ddog_php_prof_get_current_execute_data(),
                value as i64,
            )
        };
    }
}

pub struct IOProfilingStats<C: IOCollector> {
    next_sample: u64,
    poisson: Poisson<f64>,
    rng: ThreadRng,
    collector: C,
}

impl<C: IOCollector> IOProfilingStats<C> {
    fn new(lambda: f64, collector: C) -> Self {
        // Safety: this will only error if lambda <= 0
        let poisson = Poisson::new(lambda).unwrap();
        let mut stats = IOProfilingStats {
            next_sample: 0,
            poisson,
            rng: rand::thread_rng(),
            collector,
        };
        stats.next_sampling_interval();
        stats
    }

    fn next_sampling_interval(&mut self) {
        self.next_sample = self.poisson.sample(&mut self.rng) as u64;
    }

    fn track(&mut self, value: u64) {
        println!("{}", self.next_sample);
        if let Some(next_sample) = self.next_sample.checked_sub(value) {
            self.next_sample = next_sample;
            return;
        }
        self.next_sampling_interval();
        if let Some(profiler) = Profiler::get() {
            self.collector.collect(profiler, value);
        }
    }
}

thread_local! {
    static SOCKET_READ_TIME_PROFILING_STATS: RefCell<IOProfilingStats<SocketReadTimeCollector>> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_READ_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            SocketReadTimeCollector
        )
    );
    static SOCKET_WRITE_TIME_PROFILING_STATS: RefCell<IOProfilingStats<SocketWriteTimeCollector>> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            SocketWriteTimeCollector
        )
    );
    static FILE_READ_TIME_PROFILING_STATS: RefCell<IOProfilingStats<FileReadTimeCollector>> = RefCell::new(
        IOProfilingStats::new(
            FILE_READ_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            FileReadTimeCollector
        )
    );
    static FILE_WRITE_TIME_PROFILING_STATS: RefCell<IOProfilingStats<FileWriteTimeCollector>> = RefCell::new(
        IOProfilingStats::new(
            FILE_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            FileWriteTimeCollector
        )
    );
    static SOCKET_READ_SIZE_PROFILING_STATS: RefCell<IOProfilingStats<SocketReadSizeCollector>> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_READ_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            SocketReadSizeCollector
        )
    );
    static SOCKET_WRITE_SIZE_PROFILING_STATS: RefCell<IOProfilingStats<SocketWriteSizeCollector>> = RefCell::new(
        IOProfilingStats::new(
            SOCKET_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            SocketWriteSizeCollector
        )
    );
    static FILE_READ_SIZE_PROFILING_STATS: RefCell<IOProfilingStats<FileReadSizeCollector>> = RefCell::new(
        IOProfilingStats::new(
            FILE_READ_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            FileReadSizeCollector
        )
    );
    static FILE_WRITE_SIZE_PROFILING_STATS: RefCell<IOProfilingStats<FileWriteSizeCollector>> = RefCell::new(
        IOProfilingStats::new(
            FILE_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            FileWriteSizeCollector
        )
    );
}

pub fn io_prof_minit() {
    unsafe {
        let mut overwrites = vec![
            GotSymbolOverwrite {
                symbol_name: "recv",
                new_func: observed_recv as *mut (),
                orig_func: ptr::addr_of_mut!(ORIG_RECV) as *mut _ as *mut *mut (),
            },
            GotSymbolOverwrite {
                symbol_name: "send",
                new_func: observed_send as *mut (),
                orig_func: ptr::addr_of_mut!(ORIG_SEND) as *mut _ as *mut *mut (),
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
                symbol_name: "flock",
                new_func: observed_flock as *mut (),
                orig_func: ptr::addr_of_mut!(ORIG_FLOCK) as *mut _ as *mut *mut (),
            },
            GotSymbolOverwrite {
                symbol_name: "select",
                new_func: observed_select as *mut (),
                orig_func: ptr::addr_of_mut!(ORIG_SELECT) as *mut _ as *mut *mut (),
            },
            GotSymbolOverwrite {
                symbol_name: "poll",
                new_func: observed_poll as *mut (),
                orig_func: ptr::addr_of_mut!(ORIG_POLL) as *mut _ as *mut *mut (),
            },
        ];
        libc::dl_iterate_phdr(
            Some(callback),
            &mut overwrites as *mut _ as *mut libc::c_void,
        );
    };
}