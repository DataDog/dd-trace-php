use crate::bindings::{
    Elf64_Dyn, Elf64_Rela, Elf64_Sym, Elf64_Xword, DT_JMPREL, DT_NULL, DT_PLTRELSZ, DT_STRTAB,
    DT_SYMTAB, PT_DYNAMIC, R_AARCH64_JUMP_SLOT,
};
use crate::profiling::Profiler;
use crate::zend;
use libc::{c_char, c_int, c_void, dl_phdr_info};
use log::trace;
use std::ffi::CStr;
use std::ptr;
use std::time::Instant;
use std::time::SystemTime;
use std::time::UNIX_EPOCH;

fn elf64_r_type(info: Elf64_Xword) -> u32 {
    (info & 0xffffffff) as u32
}

fn elf64_r_sym(info: Elf64_Xword) -> u32 {
    (info >> 32) as u32
}

unsafe fn override_got_entry(
    info: *mut dl_phdr_info,
    symbol_name: &str,
    new_func: *mut (),
    orig_func: *mut *mut (),
) {
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
        if name == symbol_name {
            let got_entry = ((*info).dlpi_addr as usize + (*rel).r_offset as usize) as *mut *mut ();

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
                symbol_name,
                (*rel).r_offset,
                got_entry,
                *got_entry,
                *orig_func
            );

            // This works for musl based linux distros, but not for libc once
            *orig_func = libc::dlsym(libc::RTLD_NEXT, name_ptr) as *mut ();
            if (*orig_func).is_null() {
                // libc linux fallback
                *orig_func = *got_entry;
            }
            *got_entry = new_func;

            trace!(
                "  Overrode GOT entry for {} at offset {:?} (abs: {:p}) pointing to {:p} (orig function at {:p})",
                symbol_name,
                (*rel).r_offset,
                got_entry,
                *got_entry,
                *orig_func
            );

            //trace!("Successfully overridden GOT entry for {}", symbol_name);
            return;
        }
    }

    //trace!(
    //    "Failed to find symbol in relocation entries: {}",
    //    CStr::from_ptr(symbol_name).to_string_lossy()
    //);
}

unsafe extern "C" fn callback(info: *mut dl_phdr_info, _size: usize, _data: *mut c_void) -> c_int {
    let mut my_info: libc::Dl_info = std::mem::zeroed();
    if libc::dladdr(observed_write as *const c_void, &mut my_info) == 0 {
        trace!("dladdr failed");
        return 0;
    }
    let my_base_addr = my_info.dli_fbase as usize;
    let module_base_addr = (*info).dlpi_addr as usize;
    if module_base_addr == my_base_addr {
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

    if name.contains("linux-vdso") || name.contains("ld-linux") {
        return 0;
    }

    trace!(
        "ELF headers at: 0x{:x}, Library: {}",
        (*info).dlpi_addr,
        name
    );

    override_got_entry(
        info,
        "recv",
        observed_recv as *mut (),
        &mut ORIG_RECV as *mut _ as *mut *mut (),
    );

    if !name.contains("resolv") {
        // this is due to a race condition in curl vs PHP. curl will spawn a background thread to
        // do name resolution and this causes this hook to be called while maybe in the main thread
        // calling another I/O hook "at the same time". Now none of this assumes another thread
        // could be there in NTS PHP so we crash instead. Better to not hook in at this time, and
        // solve before going GA ;-)
        override_got_entry(
            info,
            "send",
            observed_send as *mut (),
            &mut ORIG_SEND as *mut _ as *mut *mut (),
        );
    }

    override_got_entry(
        info,
        "write",
        observed_write as *mut (),
        &mut ORIG_WRITE as *mut _ as *mut *mut (),
    );
    override_got_entry(
        info,
        "read",
        observed_read as *mut (),
        &mut ORIG_READ as *mut _ as *mut *mut (),
    );
    override_got_entry(
        info,
        "flock",
        observed_flock as *mut (),
        &mut ORIG_FLOCK as *mut _ as *mut *mut (),
    );
    override_got_entry(
        info,
        "select",
        observed_select as *mut (),
        &mut ORIG_SELECT as *mut _ as *mut *mut (),
    );
    override_got_entry(
        info,
        "poll",
        observed_poll as *mut (),
        &mut ORIG_POLL as *mut _ as *mut *mut (),
    );

    0
}

static mut ORIG_POLL: unsafe extern "C" fn(*mut libc::pollfd, u64, c_int) -> i32 = libc::poll;
unsafe extern "C" fn observed_poll(fds: *mut libc::pollfd, nfds: u64, timeout: c_int) -> i32 {
    let start = Instant::now();
    let fds = ORIG_POLL(fds, nfds, timeout);
    let duration = start.elapsed();
    //println!(
    //    "Observed poll of with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    let now = SystemTime::now().duration_since(UNIX_EPOCH);
    if now.is_err() {
        return fds;
    }
    if let Some(profiler) = Profiler::get() {
        // Safety: `unwrap` can be unchecked, as we checked for `is_err()`
        let now = unsafe { now.unwrap_unchecked().as_nanos() } as i64;
        let duration = duration.as_nanos() as i64;
        let execute_data = zend::ddog_php_prof_get_current_execute_data();
        profiler.collect_io(execute_data, now, duration, 0);
    }

    fds
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
    let start = Instant::now();
    let fds = ORIG_SELECT(nfds, readfds, writefds, exceptfds, timeout);
    let duration = start.elapsed();
    //println!(
    //    "Observed select of with a duration of {} nanoseconds",
    //    duration.as_nanos()
    //);

    let now = SystemTime::now().duration_since(UNIX_EPOCH);
    if now.is_err() {
        return fds;
    }
    if let Some(profiler) = Profiler::get() {
        // Safety: `unwrap` can be unchecked, as we checked for `is_err()`
        let now = unsafe { now.unwrap_unchecked().as_nanos() } as i64;
        let duration = duration.as_nanos() as i64;
        let execute_data = zend::ddog_php_prof_get_current_execute_data();
        profiler.collect_io(execute_data, now, duration, 0);
    }

    fds
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

    let now = SystemTime::now().duration_since(UNIX_EPOCH);
    if now.is_err() {
        return len;
    }
    if let Some(profiler) = Profiler::get() {
        // Safety: `unwrap` can be unchecked, as we checked for `is_err()`
        let now = unsafe { now.unwrap_unchecked().as_nanos() } as i64;
        let duration = duration.as_nanos() as i64;
        let execute_data = zend::ddog_php_prof_get_current_execute_data();
        profiler.collect_io(execute_data, now, duration, len as i64);
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

    let now = SystemTime::now().duration_since(UNIX_EPOCH);
    if now.is_err() {
        return len;
    }
    if let Some(profiler) = Profiler::get() {
        // Safety: `unwrap` can be unchecked, as we checked for `is_err()`
        let now = unsafe { now.unwrap_unchecked().as_nanos() } as i64;
        let duration = duration.as_nanos() as i64;
        let execute_data = zend::ddog_php_prof_get_current_execute_data();
        profiler.collect_io(execute_data, now, duration, len as i64);
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

    let now = SystemTime::now().duration_since(UNIX_EPOCH);
    if now.is_err() {
        return len;
    }
    if let Some(profiler) = Profiler::get() {
        // Safety: `unwrap` can be unchecked, as we checked for `is_err()`
        let now = unsafe { now.unwrap_unchecked().as_nanos() } as i64;
        let duration = duration.as_nanos() as i64;
        let execute_data = zend::ddog_php_prof_get_current_execute_data();
        profiler.collect_io(execute_data, now, duration, len as i64);
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

    let now = SystemTime::now().duration_since(UNIX_EPOCH);
    if now.is_err() {
        return len;
    }
    if let Some(profiler) = Profiler::get() {
        // Safety: `unwrap` can be unchecked, as we checked for `is_err()`
        let now = unsafe { now.unwrap_unchecked().as_nanos() } as i64;
        let duration = duration.as_nanos() as i64;
        let execute_data = zend::ddog_php_prof_get_current_execute_data();
        profiler.collect_io(execute_data, now, duration, len as i64);
    }

    len
}

static mut ORIG_FLOCK: unsafe extern "C" fn(c_int, c_int) -> c_int = libc::flock;
unsafe extern "C" fn observed_flock(fd: c_int, op: c_int) -> c_int {
    //println!("Accquire lock for fd {fd}");
    let ret = ORIG_FLOCK(fd, op);
    //println!("Got lock for fd {fd}");
    ret
}

pub fn io_prof_minit() {
    unsafe {
        libc::dl_iterate_phdr(Some(callback), std::ptr::null_mut());
    };
}
