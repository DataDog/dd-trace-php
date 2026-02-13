// The libc crate deprecates Mach-O types in favor of the mach2 crate, but we only use a few
// types and don't want to add a dependency just for that.
#![allow(deprecated)]

use super::GotSymbolOverwrite;
use libc::{c_char, c_void};
use log::{error, trace};
use std::ffi::CStr;

// Mach-O constants
const LC_SYMTAB: u32 = 0x2;
const LC_DYSYMTAB: u32 = 0xB;
const LC_SEGMENT_64: u32 = 0x19;
const S_LAZY_SYMBOL_POINTERS: u32 = 0x7;
const S_NON_LAZY_SYMBOL_POINTERS: u32 = 0x6;
const INDIRECT_SYMBOL_LOCAL: u32 = 0x80000000;
const INDIRECT_SYMBOL_ABS: u32 = 0x40000000;

// Mach-O structures not available in the `libc` crate
#[repr(C)]
struct SymtabCommand {
    cmd: u32,
    cmdsize: u32,
    symoff: u32,
    nsyms: u32,
    stroff: u32,
    strsize: u32,
}

#[repr(C)]
struct DysymtabCommand {
    cmd: u32,
    cmdsize: u32,
    ilocalsym: u32,
    nlocalsym: u32,
    iextdefsym: u32,
    nextdefsym: u32,
    iundefsym: u32,
    nundefsym: u32,
    tocoff: u32,
    ntoc: u32,
    modtaboff: u32,
    nmodtab: u32,
    extrefsymoff: u32,
    nextrefsyms: u32,
    indirectsymoff: u32,
    nindirectsyms: u32,
    extreloff: u32,
    nextrel: u32,
    locreloff: u32,
    nlocrel: u32,
}

#[repr(C)]
struct Nlist64 {
    n_strx: u32,
    n_type: u8,
    n_sect: u8,
    n_desc: u16,
    n_value: u64,
}

#[repr(C)]
struct Section64 {
    sectname: [u8; 16],
    segname: [u8; 16],
    addr: u64,
    size: u64,
    offset: u32,
    align: u32,
    reloff: u32,
    nreloc: u32,
    flags: u32,
    reserved1: u32,
    reserved2: u32,
    reserved3: u32,
}

extern "C" {
    fn _dyld_image_count() -> u32;
    fn _dyld_get_image_header(image_index: u32) -> *const libc::mach_header_64;
    fn _dyld_get_image_vmaddr_slide(image_index: u32) -> isize;
    fn _dyld_get_image_name(image_index: u32) -> *const c_char;

    fn vm_protect(
        target_task: libc::mach_port_t,
        address: libc::mach_vm_address_t,
        size: libc::mach_vm_size_t,
        set_maximum: libc::boolean_t,
        new_protection: libc::vm_prot_t,
    ) -> libc::kern_return_t;
    fn mach_task_self() -> libc::mach_port_t;
}

/// Rebind symbols in all currently loaded Mach-O images.
///
/// This implements a fishhook-style approach: for each loaded dylib/binary, parse its Mach-O
/// load commands to find `__la_symbol_ptr` and `__nl_symbol_ptr` sections, resolve symbol names
/// via the indirect symbol table, and patch matching entries.
///
/// # Safety
/// This function modifies GOT/lazy-binding pointers in loaded images. It must only be called
/// once during initialization, from a single thread, before the hooked functions are called
/// concurrently.
pub unsafe fn rebind_symbols(overwrites: &mut Vec<GotSymbolOverwrite>) {
    // Detect our own image so we skip hooking ourselves
    let mut my_info: libc::Dl_info = std::mem::zeroed();
    if libc::dladdr(rebind_symbols as *const c_void, &mut my_info) == 0 {
        error!("Did not find my own `dladdr` and therefore can't hook into the GOT.");
        return;
    }
    let my_base_addr = my_info.dli_fbase as usize;

    let image_count = _dyld_image_count();
    for i in 0..image_count {
        let header = _dyld_get_image_header(i);
        if header.is_null() {
            continue;
        }

        // Skip our own image
        if header as usize == my_base_addr {
            continue;
        }

        let slide = _dyld_get_image_vmaddr_slide(i);
        let name_ptr = _dyld_get_image_name(i);
        let name = if name_ptr.is_null() {
            "[Unknown]"
        } else {
            CStr::from_ptr(name_ptr).to_str().unwrap_or("[Unknown]")
        };

        if rebind_symbols_for_image(header, slide, overwrites) {
            trace!("Hooked into {name}");
        } else {
            trace!("Hooking {name} skipped or failed");
        }
    }
}

/// Rebind symbols for a single Mach-O image.
///
/// Walks the load commands to find LC_SYMTAB, LC_DYSYMTAB, and LC_SEGMENT_64 commands,
/// then patches symbol pointer sections.
unsafe fn rebind_symbols_for_image(
    header: *const libc::mach_header_64,
    slide: isize,
    overwrites: &mut Vec<GotSymbolOverwrite>,
) -> bool {
    if (*header).magic != libc::MH_MAGIC_64 {
        trace!("Skipping image: not a 64-bit Mach-O (magic: {:#x})", (*header).magic);
        return false;
    }

    // Load commands start right after the mach_header_64
    let mut cmd_ptr = (header as *const u8).add(std::mem::size_of::<libc::mach_header_64>());
    let ncmds = (*header).ncmds;

    let mut symtab_cmd: *const SymtabCommand = std::ptr::null();
    let mut dysymtab_cmd: *const DysymtabCommand = std::ptr::null();
    let mut linkedit_base: usize = 0;
    let mut linkedit_found = false;

    // First pass: locate LC_SYMTAB, LC_DYSYMTAB, and __LINKEDIT segment
    for _ in 0..ncmds {
        let lc = &*(cmd_ptr as *const libc::load_command);

        match lc.cmd {
            LC_SYMTAB => {
                symtab_cmd = cmd_ptr as *const SymtabCommand;
            }
            LC_DYSYMTAB => {
                dysymtab_cmd = cmd_ptr as *const DysymtabCommand;
            }
            LC_SEGMENT_64 => {
                let seg = &*(cmd_ptr as *const libc::segment_command_64);
                let segname = seg_name(seg);
                if segname == "__LINKEDIT" {
                    // linkedit_base = slide + vmaddr - fileoff
                    linkedit_base =
                        (slide as usize).wrapping_add(seg.vmaddr as usize) - seg.fileoff as usize;
                    linkedit_found = true;
                }
            }
            _ => {}
        }

        cmd_ptr = cmd_ptr.add(lc.cmdsize as usize);
    }

    if symtab_cmd.is_null() || dysymtab_cmd.is_null() || !linkedit_found {
        trace!(
            "Failed to locate required Mach-O sections (LC_SYMTAB: {}, LC_DYSYMTAB: {}, __LINKEDIT: {})",
            !symtab_cmd.is_null(),
            !dysymtab_cmd.is_null(),
            linkedit_found,
        );
        return false;
    }

    // Resolve pointers into __LINKEDIT
    let symtab = (linkedit_base + (*symtab_cmd).symoff as usize) as *const Nlist64;
    let strtab = (linkedit_base + (*symtab_cmd).stroff as usize) as *const c_char;
    let indirect_symtab =
        (linkedit_base + (*dysymtab_cmd).indirectsymoff as usize) as *const u32;

    // Second pass: find __DATA and __DATA_CONST segments with symbol pointer sections
    cmd_ptr = (header as *const u8).add(std::mem::size_of::<libc::mach_header_64>());
    let mut hooked = false;

    for _ in 0..ncmds {
        let lc = &*(cmd_ptr as *const libc::load_command);

        if lc.cmd == LC_SEGMENT_64 {
            let seg = &*(cmd_ptr as *const libc::segment_command_64);
            let segname = seg_name(seg);

            if segname == "__DATA" || segname == "__DATA_CONST" {
                let sections_ptr = cmd_ptr.add(std::mem::size_of::<libc::segment_command_64>())
                    as *const Section64;

                for j in 0..seg.nsects {
                    let section = &*sections_ptr.add(j as usize);
                    let section_type = section.flags & 0xFF; // SECTION_TYPE mask

                    if section_type != S_LAZY_SYMBOL_POINTERS
                        && section_type != S_NON_LAZY_SYMBOL_POINTERS
                    {
                        continue;
                    }

                    if rebind_symbols_in_section(
                        section,
                        slide,
                        symtab,
                        strtab,
                        indirect_symtab,
                        overwrites,
                        segname == "__DATA_CONST",
                    ) {
                        hooked = true;
                    }
                }
            }
        }

        cmd_ptr = cmd_ptr.add(lc.cmdsize as usize);
    }

    hooked
}

/// Rebind symbols in a single `__la_symbol_ptr` or `__nl_symbol_ptr` section.
unsafe fn rebind_symbols_in_section(
    section: &Section64,
    slide: isize,
    symtab: *const Nlist64,
    strtab: *const c_char,
    indirect_symtab: *const u32,
    overwrites: &mut Vec<GotSymbolOverwrite>,
    is_data_const: bool,
) -> bool {
    let num_indirect_syms = section.size as usize / std::mem::size_of::<*mut c_void>();
    let indirect_sym_indices = indirect_symtab.add(section.reserved1 as usize);
    let symbol_ptrs =
        ((slide as usize).wrapping_add(section.addr as usize)) as *mut *mut c_void;

    let mut hooked = false;

    for i in 0..num_indirect_syms {
        let symtab_index = *indirect_sym_indices.add(i);

        // Skip local and absolute symbols
        if symtab_index == INDIRECT_SYMBOL_LOCAL
            || symtab_index == INDIRECT_SYMBOL_ABS
            || symtab_index == (INDIRECT_SYMBOL_LOCAL | INDIRECT_SYMBOL_ABS)
        {
            continue;
        }

        let nlist = &*symtab.add(symtab_index as usize);
        let name_ptr = strtab.add(nlist.n_strx as usize);
        let name = match CStr::from_ptr(name_ptr).to_str() {
            Ok(n) => n,
            Err(_) => continue,
        };

        // Mach-O symbols have a leading underscore
        let name = name.strip_prefix('_').unwrap_or(name);

        for overwrite in overwrites.iter_mut() {
            if name != overwrite.symbol_name {
                continue;
            }

            let slot = symbol_ptrs.add(i);

            // For __DATA_CONST, we need to temporarily make the page writable
            if is_data_const {
                let page_size = libc::sysconf(libc::_SC_PAGESIZE) as usize;
                let page_start = (slot as usize) & !(page_size - 1);
                let result = vm_protect(
                    mach_task_self(),
                    page_start as libc::mach_vm_address_t,
                    page_size as libc::mach_vm_size_t,
                    0,
                    libc::VM_PROT_READ | libc::VM_PROT_WRITE | 0x10, // VM_PROT_COPY = 0x10
                );
                if result != 0 {
                    trace!("vm_protect failed for {name}: kern_return {result}");
                    continue;
                }
            }

            trace!(
                "Overriding symbol pointer for {} at {:p} pointing to {:p} (orig function at {:p})",
                overwrite.symbol_name,
                slot,
                *slot,
                *overwrite.orig_func,
            );

            // Use dlsym(RTLD_DEFAULT) to get the original, similar to the ELF path
            *overwrite.orig_func = libc::dlsym(libc::RTLD_DEFAULT, name_ptr) as *mut ();
            if (*overwrite.orig_func).is_null() {
                *overwrite.orig_func = *slot as *mut ();
            }
            *slot = overwrite.new_func as *mut c_void;
            hooked = true;

            // Restore protection for __DATA_CONST
            if is_data_const {
                let page_size = libc::sysconf(libc::_SC_PAGESIZE) as usize;
                let page_start = (slot as usize) & !(page_size - 1);
                vm_protect(
                    mach_task_self(),
                    page_start as libc::mach_vm_address_t,
                    page_size as libc::mach_vm_size_t,
                    0,
                    libc::VM_PROT_READ,
                );
            }

            break;
        }
    }

    hooked
}

/// Extract the segment name from a `segment_command_64` as a `&str`.
fn seg_name(seg: &libc::segment_command_64) -> &str {
    let bytes = &seg.segname;
    let len = bytes.iter().position(|&b| b == 0).unwrap_or(bytes.len());
    // SAFETY: segment names are always ASCII; cast from &[i8] to &[u8] is safe
    // because i8 and u8 have the same size and alignment.
    let bytes: &[u8] =
        unsafe { std::slice::from_raw_parts(bytes.as_ptr() as *const u8, len) };
    std::str::from_utf8(bytes).unwrap_or("")
}
