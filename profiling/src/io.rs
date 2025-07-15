use crate::bindings::{
    Elf64_Dyn, Elf64_Rela, Elf64_Sym, Elf64_Xword, DT_JMPREL, DT_NULL, DT_PLTRELSZ, DT_STRTAB,
    DT_SYMTAB, PT_DYNAMIC, R_AARCH64_JUMP_SLOT, R_X86_64_JUMP_SLOT,
};
use crate::profiling::Profiler;
use crate::{zend, RefCellExt, REQUEST_LOCALS};
use ahash::{HashMap, HashMapExt};
use libc::{c_char, c_int, c_void, dl_phdr_info, fstat, stat, S_IFMT, S_IFSOCK};
use log::{error, trace};
use rand::rngs::ThreadRng;
use rand_distr::{Distribution, Poisson};
use std::cell::RefCell;
use std::ffi::CStr;
use std::mem::MaybeUninit;
use std::os::unix::io::RawFd;
use std::ptr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::Instant;

fn elf64_r_type(info: Elf64_Xword) -> u32 {
    (info & 0xffffffff) as u32
}

fn elf64_r_sym(info: Elf64_Xword) -> u32 {
    (info >> 32) as u32
}

/// Override the GOT entry for symbols specified in `overwrites`.
///
/// See: https://cs4401.walls.ninja/notes/lecture/basics_global_offset_table.html
/// See: https://bottomupcs.com/ch09s03.html
/// See: https://www.codeproject.com/articles/1032231/what-is-the-symbol-table-and-what-is-the-global-of
///
/// Safety: Why is anything happening in in here safe? Well generally we can say all of the pointer
/// arithmetics are safe because the dynamic library the `info` is pointing to was loaded by the
/// dynamic linker prior to us messing with the global offset table. If the dynamic library would
/// not be a valid ELF64, the dynamic linker would have not loaded it.
unsafe fn override_got_entry(
    info: *mut dl_phdr_info,
    overwrites: *mut Vec<GotSymbolOverwrite>,
) -> bool {
    let phdr = (*info).dlpi_phdr;

    // Locate the dynamic programm header (`PT_DYNAMIC`)
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
        return false;
    }

    let mut rel_plt: *mut Elf64_Rela = ptr::null_mut();
    let mut rel_plt_size: usize = 0;
    let mut symtab: *mut Elf64_Sym = ptr::null_mut();
    let mut strtab: *const c_char = ptr::null();

    // The dynamic programm header (`PT_DYNAMIC`) has different sections. We are interessted in the
    // procedure linkage table (PLT in `DT_JMPREL`), the size of the PLT (`DT_PLTRELSZ`), the
    // symbol table (`DT_SYMTAB`) and the the string table for the symbol names (`DT_STRTAB`).
    //
    // Addresses in here are sometimes relative, sometimes absolute
    // - on musl, addresses are relative
    // - on glibc, addresses are absolutes
    // https://elixir.bootlin.com/glibc/glibc-2.36/source/elf/get-dynamic-info.h#L84
    let mut dyn_iter = dyn_ptr;
    loop {
        let d_tag = (*dyn_iter).d_tag as u32;
        if d_tag == DT_NULL {
            break;
        }
        match d_tag {
            DT_JMPREL => {
                // Relocation entries for the PLT (Procedure Linkage Table)
                if ((*dyn_iter).d_un.d_ptr as usize) < ((*info).dlpi_addr as usize) {
                    rel_plt = ((*info).dlpi_addr as usize + (*dyn_iter).d_un.d_ptr as usize)
                        as *mut Elf64_Rela;
                } else {
                    rel_plt = (*dyn_iter).d_un.d_ptr as *mut Elf64_Rela;
                }
            }
            DT_PLTRELSZ => {
                // Size of the PLT relocation entries
                rel_plt_size = (*dyn_iter).d_un.d_val as usize;
            }
            DT_SYMTAB => {
                // The symbol table
                if ((*dyn_iter).d_un.d_ptr as usize) < ((*info).dlpi_addr as usize) {
                    symtab = ((*info).dlpi_addr as usize + (*dyn_iter).d_un.d_ptr as usize)
                        as *mut Elf64_Sym;
                } else {
                    symtab = (*dyn_iter).d_un.d_ptr as *mut Elf64_Sym;
                }
            }
            DT_STRTAB => {
                // The string table for the symbol names
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
        trace!("Failed to locate required ELF sections (`DT_JMPREL`, `DT_PLTRELSZ`, `DT_SYMTAB` and `DT_STRTAB`)");
        return false;
    }

    let num_relocs = rel_plt_size / std::mem::size_of::<Elf64_Rela>();

    // For each symbol we want to overwrite (from `overwrites`), we scan the relocation entries.
    // Once the matching symbol name is found, patch its GOT entry to point to our new function.
    for overwrite in &mut *overwrites {
        for i in 0..num_relocs {
            let rel = rel_plt.add(i);
            let r_type = elf64_r_type((*rel).r_info);

            // Only handle JUMP_SLOT relocations
            if r_type != R_AARCH64_JUMP_SLOT && r_type != R_X86_64_JUMP_SLOT {
                continue;
            }

            // Get the symbol index for this relocation, then the symbol struct
            let sym_index = elf64_r_sym((*rel).r_info) as usize;
            let sym = symtab.add(sym_index);

            // Access the symbol name via the string table
            let name_offset = (*sym).st_name as isize;
            let name_ptr = strtab.offset(name_offset);
            let name = CStr::from_ptr(name_ptr).to_str().unwrap_or("");

            if name == overwrite.symbol_name {
                // Calculate the GOT entry address. Per the ELF spec, `r_offset` for pointer-sized
                // relocations (such as GOT entries) is guaranteed to be pointer-aligned, see:
                // https://github.com/ARM-software/abi-aa/blob/main/aaelf64/aaelf64.rst#5733relocation-operations
                let got_entry =
                    ((*info).dlpi_addr as usize + (*rel).r_offset as usize) as *mut *mut ();

                // Change memory protection so we can write to the GOT entry
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
                    return false;
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
                continue;
            }
        }
    }
    true
}

/// Callback function that should be passed to `libc::dl_iterate_phdr()` and gets called for every
/// shared object.
unsafe extern "C" fn callback(info: *mut dl_phdr_info, _size: usize, data: *mut c_void) -> c_int {
    let overwrites = &mut *(data as *mut Vec<GotSymbolOverwrite>);

    // detect myself ...
    let mut my_info: libc::Dl_info = std::mem::zeroed();
    if libc::dladdr(callback as *const c_void, &mut my_info) == 0 {
        error!("Did not find my own `dladdr` and therefore can't hook into the GOT.");
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

    // I guess if we try to hook into GOT from `linux-vdso` or `ld-linux` our best outcome will be
    // that nothing happens, but most likely we'll crash and we should avoid that.
    if name.contains("linux-vdso") || name.contains("ld-linux") {
        return 0;
    }

    if override_got_entry(info, overwrites) {
        trace!("Hooked into {name}");
    } else {
        trace!("Hooking {name} failed");
    }

    0
}

struct GotSymbolOverwrite {
    symbol_name: &'static str,
    new_func: *mut (),
    orig_func: *mut *mut (),
}

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
                Some(callback),
                &mut overwrites as *mut _ as *mut libc::c_void,
            );
        };
    }
}
