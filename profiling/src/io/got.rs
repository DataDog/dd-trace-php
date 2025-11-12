use crate::bindings::{
    Elf64_Dyn, Elf64_Rela, Elf64_Sym, Elf64_Xword, DT_JMPREL, DT_NULL, DT_PLTRELSZ, DT_STRTAB,
    DT_SYMTAB, PT_DYNAMIC, R_AARCH64_JUMP_SLOT, R_X86_64_JUMP_SLOT,
};
use libc::{c_char, c_int, c_void, dl_phdr_info};
use log::{error, trace};
use std::ffi::CStr;
use std::ptr;

fn elf64_r_type(info: Elf64_Xword) -> u32 {
    (info & 0xffffffff) as u32
}

fn elf64_r_sym(info: Elf64_Xword) -> u32 {
    (info >> 32) as u32
}

pub struct GotSymbolOverwrite {
    pub symbol_name: &'static str,
    pub new_func: *mut (),
    pub orig_func: *mut *mut (),
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
pub unsafe extern "C" fn callback(info: *mut dl_phdr_info, _size: usize, data: *mut c_void) -> c_int {
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
