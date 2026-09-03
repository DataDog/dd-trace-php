use super::{
    restore_matches_image, restore_slot_if_owned, slot_fits_range, GotHookState, GotSlotRestore,
};
use crate::profiling::bindings::{
    Elf64_Dyn, Elf64_Rela, Elf64_Sym, Elf64_Xword, DT_JMPREL, DT_NULL, DT_PLTRELSZ, DT_STRTAB,
    DT_SYMTAB, PT_DYNAMIC, PT_LOAD, R_AARCH64_JUMP_SLOT, R_X86_64_JUMP_SLOT,
};
use libc::{c_char, c_int, c_void, dl_phdr_info};
use log::{error, trace};
use std::ffi::CStr;
use std::ptr;
use std::sync::OnceLock;

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
    image_name: &[u8],
    state: &mut GotHookState,
) -> bool {
    let phdr = (*info).dlpi_phdr;

    // Locate the dynamic program header (`PT_DYNAMIC`) and RELRO segment (`PT_GNU_RELRO`)
    let mut dyn_ptr: *const Elf64_Dyn = ptr::null();
    let mut dyn_count: usize = 0;
    let mut relro_range: Option<(usize, usize)> = None;
    for i in 0..(*info).dlpi_phnum {
        let phdr_i = phdr.offset(i as isize);
        if (*phdr_i).p_type == PT_DYNAMIC {
            dyn_ptr = ((*info).dlpi_addr as usize + (*phdr_i).p_vaddr as usize) as *const Elf64_Dyn;
            dyn_count = (*phdr_i).p_memsz as usize / std::mem::size_of::<Elf64_Dyn>();
        } else if (*phdr_i).p_type == libc::PT_GNU_RELRO {
            let start = (*info).dlpi_addr as usize + (*phdr_i).p_vaddr as usize;
            let end = start + (*phdr_i).p_memsz as usize;
            relro_range = Some((start, end));
        }
    }
    if dyn_ptr.is_null() || dyn_count == 0 {
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
    for _ in 0..dyn_count {
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

    // Scan relocation entries once and match against symbols we want to overwrite.
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

        for overwrite in state.overwrites.iter_mut() {
            if name == overwrite.symbol_name {
                // Calculate the GOT entry address. Per the ELF spec, `r_offset` for pointer-sized
                // relocations (such as GOT entries) is guaranteed to be pointer-aligned, see:
                // https://github.com/ARM-software/abi-aa/blob/main/aaelf64/aaelf64.rst#5733relocation-operations
                let got_entry =
                    ((*info).dlpi_addr as usize + (*rel).r_offset as usize) as *mut *mut ();

                let is_relro = if let Some((start, end)) = relro_range {
                    (got_entry as usize) >= start && (got_entry as usize) < end
                } else {
                    false
                };

                // Change memory protection so we can write to the GOT entry if protected by RELRO
                let page_size = libc::sysconf(libc::_SC_PAGESIZE) as usize;
                let aligned_addr = (got_entry as usize) & !(page_size - 1);
                if is_relro
                    && libc::mprotect(
                        aligned_addr as *mut c_void,
                        page_size,
                        libc::PROT_READ | libc::PROT_WRITE,
                    ) != 0
                {
                    let err = *libc::__errno_location();
                    trace!("mprotect failed: {}", err);
                    return false;
                }

                let original = *got_entry;
                if original == overwrite.new_func {
                    continue;
                }

                trace!(
                    "Overriding GOT entry for {} at offset {:?} (abs: {:p}) pointing to {:p} (orig function at {:p})",
                    overwrite.symbol_name,
                    (*rel).r_offset,
                    got_entry,
                    original,
                    *overwrite.orig_func
                );

                // This works for musl based linux distros, but not for libc once
                *overwrite.orig_func = libc::dlsym(libc::RTLD_NEXT, name_ptr) as *mut ();
                if (*overwrite.orig_func).is_null() {
                    // libc linux fallback
                    *overwrite.orig_func = original;
                }
                state.restores.push(GotSlotRestore {
                    image: (*info).dlpi_addr as usize,
                    image_name: image_name.into(),
                    slot: got_entry as usize,
                    original: original as usize,
                    replacement: overwrite.new_func as usize,
                });
                *got_entry = overwrite.new_func;

                if is_relro {
                    libc::mprotect(aligned_addr as *mut c_void, page_size, libc::PROT_READ);
                }
                break;
            }
        }
    }
    true
}

/// Callback function that should be passed to `libc::dl_iterate_phdr()` and gets called for every
/// shared object.
pub unsafe extern "C" fn callback(
    info: *mut dl_phdr_info,
    _size: usize,
    data: *mut c_void,
) -> c_int {
    let state = &mut *(data as *mut GotHookState);

    // detect myself (cached once across iterations)
    static MY_BASE_ADDR: OnceLock<usize> = OnceLock::new();
    let my_base_addr = *MY_BASE_ADDR.get_or_init(|| {
        let mut my_info: libc::Dl_info = unsafe { std::mem::zeroed() };
        if unsafe { libc::dladdr(callback as *const c_void, &mut my_info) } == 0 {
            error!("Did not find my own `dladdr` and therefore can't hook into the GOT.");
            0
        } else {
            my_info.dli_fbase as usize
        }
    });
    if my_base_addr == 0 {
        return 0;
    }
    let module_base_addr = (*info).dlpi_addr as usize;
    if module_base_addr == my_base_addr {
        // "this" lib is actually me: skipping GOT hooking for myself
        return 0;
    }

    let image_name = if (*info).dlpi_name.is_null() {
        &[][..]
    } else {
        CStr::from_ptr((*info).dlpi_name).to_bytes()
    };
    let name = if image_name.is_empty() {
        "[Executable]"
    } else {
        std::str::from_utf8(image_name).unwrap_or("[Unknown]")
    };

    // I guess if we try to hook into GOT from `linux-vdso` or `ld-linux` our best outcome will be
    // that nothing happens, but most likely we'll crash and we should avoid that.
    if name.contains("linux-vdso") || name.contains("ld-linux") {
        return 0;
    }

    if override_got_entry(info, image_name, state) {
        trace!("Hooked into {name}");
    } else {
        trace!("Hooking {name} failed");
    }

    0
}

struct RestoreState<'a> {
    restores: &'a [GotSlotRestore],
    complete: bool,
}

pub unsafe fn restore_symbols(restores: &mut Vec<GotSlotRestore>) -> bool {
    let complete = {
        let mut state = RestoreState {
            restores,
            complete: true,
        };
        libc::dl_iterate_phdr(
            Some(restore_callback),
            &mut state as *mut _ as *mut libc::c_void,
        );
        state.complete
    };
    restores.clear();
    complete
}

unsafe fn slot_belongs_to_image(info: *mut dl_phdr_info, slot: usize) -> bool {
    for i in 0..(*info).dlpi_phnum {
        let phdr = &*(*info).dlpi_phdr.add(i as usize);
        if phdr.p_type != PT_LOAD {
            continue;
        }
        let start = ((*info).dlpi_addr as usize).wrapping_add(phdr.p_vaddr as usize);
        if slot_fits_range(slot, start, phdr.p_memsz as usize) {
            return true;
        }
    }
    false
}

unsafe extern "C" fn restore_callback(
    info: *mut dl_phdr_info,
    _size: usize,
    data: *mut c_void,
) -> c_int {
    let state = &mut *(data as *mut RestoreState<'_>);
    let restores = state.restores;
    let complete = &mut state.complete;
    let image = (*info).dlpi_addr as usize;
    let image_name = if (*info).dlpi_name.is_null() {
        &[][..]
    } else {
        CStr::from_ptr((*info).dlpi_name).to_bytes()
    };
    let page_size = libc::sysconf(libc::_SC_PAGESIZE) as usize;

    for restore in restores
        .iter()
        .rev()
        .filter(|restore| restore_matches_image(restore, image, image_name))
    {
        if !slot_belongs_to_image(info, restore.slot) {
            trace!(
                "Not restoring GOT entry at {:#x}: it is outside the loaded image",
                restore.slot
            );
            *complete = false;
            continue;
        }
        let slot = restore.slot as *mut *mut ();
        if *slot as usize != restore.replacement {
            trace!(
                "Not restoring GOT entry at {:p}: it was replaced after our hook",
                slot
            );
            *complete = false;
            continue;
        }

        let aligned_addr = restore.slot & !(page_size - 1);
        if libc::mprotect(
            aligned_addr as *mut c_void,
            page_size,
            libc::PROT_READ | libc::PROT_WRITE,
        ) != 0
        {
            let err = *libc::__errno_location();
            trace!("mprotect failed while restoring GOT entry at {slot:p}: {err}");
            *complete = false;
            continue;
        }

        if restore_slot_if_owned(restore) {
            trace!("Restored GOT entry at {slot:p}");
        } else {
            *complete = false;
        }
    }

    0
}
