// Mach-O symbol rebinding for macOS (the equivalent of GOT hooking on ELF/Linux).
//
// On macOS, calls to external functions (e.g. `read()`) go through pointer slots that the
// dynamic linker (`dyld`) fills in. By overwriting these pointers we redirect calls to our
// instrumented versions. This is the same idea as fishhook: https://github.com/facebook/fishhook
//
// ## Mach-O layout (the parts we care about)
//
//   mach_header_64
//   ├─ LC_SEGMENT_64 __TEXT, __DATA, __DATA_CONST, __LINKEDIT
//   ├─ LC_SYMTAB      → symbol table + string table  (in __LINKEDIT)
//   └─ LC_DYSYMTAB    → indirect symbol table         (in __LINKEDIT)
//
// Symbol pointer slots live in __DATA/__DATA_CONST sections of type `S_LAZY_SYMBOL_POINTERS`
// (resolved on first call) or `S_NON_LAZY_SYMBOL_POINTERS` (resolved at load time). To map a
// slot to its symbol name we follow a chain of indirections:
//
//   slot[i] ──▶ indirect_symtab[section.reserved1 + i] ──▶ symtab[idx].n_strx ──▶ "_recv"
//
// All three tables (symbol table, string table, indirect symbol table) live in __LINKEDIT.
// Their file offsets come from LC_SYMTAB/LC_DYSYMTAB; at runtime we convert them via:
//
//   linkedit_base = slide + __LINKEDIT.vmaddr - __LINKEDIT.fileoff
//   runtime_ptr   = linkedit_base + file_offset
//
// The "slide" is the ASLR offset: each image is loaded at a random displacement from its
// preferred base address, and all in-file virtual addresses must be adjusted by this amount.
//
// ## __DATA_CONST and copy-on-write
//
// `__DATA` is writable — we patch it directly. `__DATA_CONST` is made read-only by dyld after
// binding, so we must temporarily call `vm_protect()` with `VM_PROT_COPY` before writing.
//
// `VM_PROT_COPY` triggers copy-on-write: macOS shares physical pages of the same dylib across
// processes, so a plain writable mapping would corrupt every process. With COW, the kernel
// lazily allocates a private page on first write:
//
//   Before write:                     After write:
//   Process A ─┐                      Process A ──▶ [private page: patched ptr]
//              ├──▶ [shared page]
//   Process B ─┘                      Process B ──▶ [shared page: original ptr]
//
// Restoring `VM_PROT_READ` afterwards makes our private copy read-only again — we keep the
// patched version and other processes are unaffected.
//
// ## References
//
// - Apple Mach-O format: https://developer.apple.com/documentation/kernel/mach_header_64
// - Apple dyld source: https://opensource.apple.com/source/dyld/
// - Mach-O headers: <mach-o/loader.h>, <mach-o/nlist.h>

// The libc crate deprecates Mach-O types in favor of the mach2 crate, but we only use a few
// types and don't want to add a dependency just for that.
#![allow(deprecated)]

use super::GotSymbolOverwrite;
use libc::{c_char, c_void};
use log::{error, trace};
use std::ffi::CStr;

// ----- Mach-O load command types -----
// These identify what kind of metadata a load command carries.
// See: <mach-o/loader.h>

/// LC_SYMTAB: Points to the symbol table and string table in __LINKEDIT.
const LC_SYMTAB: u32 = 0x2;
/// LC_DYSYMTAB: Points to the dynamic symbol table (including the indirect symbol table).
const LC_DYSYMTAB: u32 = 0xB;
/// LC_SEGMENT_64: Describes a 64-bit memory segment (__TEXT, __DATA, __LINKEDIT, etc.).
const LC_SEGMENT_64: u32 = 0x19;

// ----- Section types (lower 8 bits of section.flags) -----
// These identify the kind of data stored in a section.

/// Lazily-bound function pointers. dyld resolves these on first call.
const S_LAZY_SYMBOL_POINTERS: u32 = 0x7;
/// Non-lazily-bound function pointers. dyld resolves these at load time.
const S_NON_LAZY_SYMBOL_POINTERS: u32 = 0x6;

// ----- Special indirect symbol table entries -----
// Some entries in the indirect symbol table don't refer to real symbols.

/// The pointer is for a local (non-external) symbol — skip it.
const INDIRECT_SYMBOL_LOCAL: u32 = 0x80000000;
/// The pointer is for an absolute symbol — skip it.
const INDIRECT_SYMBOL_ABS: u32 = 0x40000000;

/// VM_PROT_COPY: When combined with vm_protect, creates a copy-on-write mapping.
/// Required to make __DATA_CONST temporarily writable without affecting shared pages.
const VM_PROT_COPY: libc::vm_prot_t = 0x10;

// ----- Mach-O structures not available in the `libc` crate -----
// These mirror the C structs from <mach-o/loader.h> and <mach-o/nlist.h>.
// They must be `#[repr(C)]` to match the exact memory layout the dynamic linker produces.

/// Corresponds to `struct symtab_command` in <mach-o/loader.h>.
/// Tells us where the symbol table and string table are located in the file (as offsets
/// into __LINKEDIT).
#[repr(C)]
struct SymtabCommand {
    cmd: u32,
    cmdsize: u32,
    /// File offset of the symbol table (array of Nlist64 entries).
    symoff: u32,
    /// Number of symbol table entries.
    nsyms: u32,
    /// File offset of the string table (null-terminated strings, indexed by Nlist64.n_strx).
    stroff: u32,
    /// Size of the string table in bytes.
    strsize: u32,
}

/// Corresponds to `struct dysymtab_command` in <mach-o/loader.h>.
/// We only care about the indirect symbol table fields (`indirectsymoff`/`nindirectsyms`).
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
    /// File offset of the indirect symbol table (array of u32 symbol-table indices).
    indirectsymoff: u32,
    /// Number of entries in the indirect symbol table.
    nindirectsyms: u32,
    extreloff: u32,
    nextrel: u32,
    locreloff: u32,
    nlocrel: u32,
}

/// Corresponds to `struct nlist_64` in <mach-o/nlist.h>.
/// Each entry in the symbol table describes one symbol.
#[repr(C)]
struct Nlist64 {
    /// Index into the string table where this symbol's name starts.
    /// Note: Mach-O symbol names have a leading underscore (e.g. `_recv` for the C function
    /// `recv`).
    n_strx: u32,
    n_type: u8,
    n_sect: u8,
    n_desc: u16,
    n_value: u64,
}

/// Corresponds to `struct section_64` in <mach-o/loader.h>.
/// Describes a section within a segment (e.g. `__la_symbol_ptr` within `__DATA`).
#[repr(C)]
struct Section64 {
    sectname: [u8; 16],
    segname: [u8; 16],
    /// Virtual memory address of this section (before ASLR slide).
    addr: u64,
    /// Size of this section in bytes. For symbol pointer sections, dividing by pointer size
    /// gives the number of pointer entries.
    size: u64,
    offset: u32,
    align: u32,
    reloff: u32,
    nreloc: u32,
    /// Section type in the lower 8 bits (S_LAZY_SYMBOL_POINTERS, etc.) plus attributes in
    /// upper bits.
    flags: u32,
    /// For symbol pointer sections: the index into the indirect symbol table where this
    /// section's entries begin.
    reserved1: u32,
    reserved2: u32,
    reserved3: u32,
}

// ----- macOS dyld API -----
// These functions are provided by the dynamic linker and let us enumerate all loaded images
// (the main executable and every loaded dylib).
extern "C" {
    /// Returns the number of currently loaded Mach-O images.
    fn _dyld_image_count() -> u32;
    /// Returns a pointer to the mach_header_64 for the image at the given index.
    fn _dyld_get_image_header(image_index: u32) -> *const libc::mach_header_64;
    /// Returns the ASLR slide for the image at the given index.
    fn _dyld_get_image_vmaddr_slide(image_index: u32) -> isize;
    /// Returns the file path of the image at the given index.
    fn _dyld_get_image_name(image_index: u32) -> *const c_char;

    /// Mach kernel call to change memory protection on a range of pages.
    /// Used to make __DATA_CONST temporarily writable.
    fn vm_protect(
        target_task: libc::mach_port_t,
        address: libc::mach_vm_address_t,
        size: libc::mach_vm_size_t,
        set_maximum: libc::boolean_t,
        new_protection: libc::vm_prot_t,
    ) -> libc::kern_return_t;
    /// Returns the Mach port for the current task (needed by vm_protect).
    fn mach_task_self() -> libc::mach_port_t;
}

/// Rebind symbols in all currently loaded Mach-O images.
///
/// This implements a [fishhook](https://github.com/facebook/fishhook)-style approach:
/// for each loaded dylib/binary, parse its Mach-O load commands to find `__la_symbol_ptr`
/// and `__nl_symbol_ptr` sections, resolve symbol names via the indirect symbol table, and
/// patch matching entries to redirect to our instrumented functions.
///
/// Note: This only hooks images that are already loaded. Dylibs loaded later via `dlopen()`
/// will NOT be hooked. This is fine for our use case because PHP extensions are loaded before
/// the first RINIT where we call this.
///
/// # Safety
/// This function modifies symbol pointers in loaded images. It must only be called once during
/// initialization, from a single thread, before the hooked functions are called concurrently.
/// The pointer arithmetic is safe because all images were successfully loaded by dyld — if an
/// image had an invalid Mach-O structure, dyld would have rejected it.
pub unsafe fn rebind_symbols(overwrites: &mut Vec<GotSymbolOverwrite>) {
    // Use dladdr on one of our own functions to find our image's base address, so we can
    // skip patching our own image (we don't want to hook our own calls to libc).
    let mut my_info: libc::Dl_info = std::mem::zeroed();
    if libc::dladdr(rebind_symbols as *const c_void, &mut my_info) == 0 {
        error!("Did not find my own `dladdr` and therefore can't hook into the GOT.");
        return;
    }
    let my_base_addr = my_info.dli_fbase as usize;

    // Iterate over every loaded Mach-O image (main executable + all dylibs)
    let image_count = _dyld_image_count();
    for i in 0..image_count {
        let header = _dyld_get_image_header(i);
        if header.is_null() {
            continue;
        }

        // Skip our own image — we don't want to intercept our own libc calls
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
/// This function does two passes over the load commands:
///
/// **Pass 1**: Locate the three pieces of metadata we need:
///   - `LC_SYMTAB` → symbol table (maps index → symbol name via string table)
///   - `LC_DYSYMTAB` → indirect symbol table (maps symbol-pointer slot → symbol table index)
///   - `__LINKEDIT` segment → base address for computing runtime pointers from file offsets
///
/// **Pass 2**: Walk `__DATA` and `__DATA_CONST` segments, find symbol pointer sections
/// (`__la_symbol_ptr` / `__nl_symbol_ptr`), and patch matching entries.
unsafe fn rebind_symbols_for_image(
    header: *const libc::mach_header_64,
    slide: isize,
    overwrites: &mut Vec<GotSymbolOverwrite>,
) -> bool {
    if (*header).magic != libc::MH_MAGIC_64 {
        trace!("Skipping image: not a 64-bit Mach-O (magic: {:#x})", (*header).magic);
        return false;
    }

    // Load commands are stored sequentially right after the mach_header_64. Each command has
    // a `cmd` type and `cmdsize` telling us how many bytes to skip to reach the next command.
    let mut cmd_ptr = (header as *const u8).add(std::mem::size_of::<libc::mach_header_64>());
    let ncmds = (*header).ncmds;

    let mut symtab_cmd: *const SymtabCommand = std::ptr::null();
    let mut dysymtab_cmd: *const DysymtabCommand = std::ptr::null();
    let mut linkedit_base: usize = 0;
    let mut linkedit_found = false;

    // ---- Pass 1: Locate LC_SYMTAB, LC_DYSYMTAB, and __LINKEDIT segment ----
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
                    // Compute the base address for __LINKEDIT data at runtime.
                    // File offsets in LC_SYMTAB/LC_DYSYMTAB are relative to the start of the
                    // file. At runtime, __LINKEDIT is mapped at (vmaddr + slide). By subtracting
                    // the file offset of __LINKEDIT itself, we get a base we can add any file
                    // offset to in order to get a valid runtime pointer.
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

    // Convert file offsets from LC_SYMTAB and LC_DYSYMTAB into runtime pointers using
    // linkedit_base (see diagram in the module-level documentation).
    let symtab = (linkedit_base + (*symtab_cmd).symoff as usize) as *const Nlist64;
    let strtab = (linkedit_base + (*symtab_cmd).stroff as usize) as *const c_char;
    let indirect_symtab =
        (linkedit_base + (*dysymtab_cmd).indirectsymoff as usize) as *const u32;

    // ---- Pass 2: Find symbol pointer sections in __DATA / __DATA_CONST and patch them ----
    cmd_ptr = (header as *const u8).add(std::mem::size_of::<libc::mach_header_64>());
    let mut hooked = false;

    for _ in 0..ncmds {
        let lc = &*(cmd_ptr as *const libc::load_command);

        if lc.cmd == LC_SEGMENT_64 {
            let seg = &*(cmd_ptr as *const libc::segment_command_64);
            let segname = seg_name(seg);

            // Symbol pointers live in __DATA (writable) or __DATA_CONST (read-only after
            // dyld finishes binding). Other segments (__TEXT, __LINKEDIT) don't contain
            // symbol pointers we can patch.
            if segname == "__DATA" || segname == "__DATA_CONST" {
                // Sections are stored immediately after the segment_command_64 struct
                let sections_ptr = cmd_ptr.add(std::mem::size_of::<libc::segment_command_64>())
                    as *const Section64;

                for j in 0..seg.nsects {
                    let section = &*sections_ptr.add(j as usize);
                    let section_type = section.flags & 0xFF; // Lower 8 bits = section type

                    // Only process symbol pointer sections — skip other sections like
                    // __got, __mod_init_func, __const, etc.
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
///
/// For each pointer slot in the section:
/// 1. Look up the corresponding entry in the indirect symbol table (at offset `section.reserved1
///    + i`) to get a symbol table index.
/// 2. Use that index into the symbol table (nlist64 array) to find the symbol's string table
///    offset (`n_strx`).
/// 3. Read the symbol name from the string table. Mach-O symbols have a leading underscore
///    (e.g. `_recv` for C's `recv()`), which we strip for comparison.
/// 4. If the name matches one of our overwrites, save the current pointer as the "original"
///    function and replace it with our instrumented version.
unsafe fn rebind_symbols_in_section(
    section: &Section64,
    slide: isize,
    symtab: *const Nlist64,
    strtab: *const c_char,
    indirect_symtab: *const u32,
    overwrites: &mut Vec<GotSymbolOverwrite>,
    is_data_const: bool,
) -> bool {
    // Each slot in the section is one pointer (8 bytes on 64-bit). The number of slots is
    // the section size divided by pointer size.
    let num_indirect_syms = section.size as usize / std::mem::size_of::<*mut c_void>();

    // The indirect symbol table entries for this section start at index `section.reserved1`.
    // Entry `indirect_sym_indices[i]` tells us which symbol table entry corresponds to slot `i`.
    let indirect_sym_indices = indirect_symtab.add(section.reserved1 as usize);

    // The actual pointer slots in memory (adjusted by ASLR slide).
    let symbol_ptrs =
        ((slide as usize).wrapping_add(section.addr as usize)) as *mut *mut c_void;

    let page_size = libc::sysconf(libc::_SC_PAGESIZE) as usize;
    let mut hooked = false;

    for i in 0..num_indirect_syms {
        // Step 1: Get the symbol table index from the indirect symbol table
        let symtab_index = *indirect_sym_indices.add(i);

        // Skip special entries that don't refer to real external symbols
        if symtab_index == INDIRECT_SYMBOL_LOCAL
            || symtab_index == INDIRECT_SYMBOL_ABS
            || symtab_index == (INDIRECT_SYMBOL_LOCAL | INDIRECT_SYMBOL_ABS)
        {
            continue;
        }

        // Step 2: Look up the symbol in the symbol table to get its name
        let nlist = &*symtab.add(symtab_index as usize);
        let name_ptr = strtab.add(nlist.n_strx as usize);
        let name = match CStr::from_ptr(name_ptr).to_str() {
            Ok(n) => n,
            Err(_) => continue,
        };

        // Step 3: Strip the Mach-O leading underscore (e.g. "_recv" → "recv") so we can
        // compare against our overwrite list which uses plain C names.
        let name = name.strip_prefix('_').unwrap_or(name);

        // Step 4: Check if this symbol is one we want to hook
        for overwrite in overwrites.iter_mut() {
            if name != overwrite.symbol_name {
                continue;
            }

            let slot = symbol_ptrs.add(i);

            // __DATA_CONST is read-only at this point (dyld marks it read-only after initial
            // binding). We need to temporarily make the page writable using vm_protect with
            // VM_PROT_COPY, which creates a copy-on-write mapping so we only affect our
            // process, not shared pages.
            if is_data_const {
                let page_start = (slot as usize) & !(page_size - 1);
                let result = vm_protect(
                    mach_task_self(),
                    page_start as libc::mach_vm_address_t,
                    page_size as libc::mach_vm_size_t,
                    0,
                    libc::VM_PROT_READ | libc::VM_PROT_WRITE | VM_PROT_COPY,
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

            // Save the original function pointer from the slot before we overwrite it.
            // This is written on every matching image (last writer wins), but that's fine:
            // by first RINIT all lazy bindings for common libc functions are resolved, so
            // every image's slot points to the same canonical address in libSystem.
            *overwrite.orig_func = *slot as *mut ();
            *slot = overwrite.new_func as *mut c_void;
            hooked = true;

            // Restore read-only protection for __DATA_CONST
            if is_data_const {
                let page_start = (slot as usize) & !(page_size - 1);
                vm_protect(
                    mach_task_self(),
                    page_start as libc::mach_vm_address_t,
                    page_size as libc::mach_vm_size_t,
                    0,
                    libc::VM_PROT_READ,
                );
            }

            // Each slot can only match one overwrite, so stop searching
            break;
        }
    }

    hooked
}

/// Extract the segment name from a `segment_command_64` as a `&str`.
///
/// Segment names are 16-byte fixed arrays (e.g. `__DATA\0\0\0\0\0\0\0\0\0\0`), so we find the
/// first null byte and return only the valid portion.
fn seg_name(seg: &libc::segment_command_64) -> &str {
    let bytes = &seg.segname;
    let len = bytes.iter().position(|&b| b == 0).unwrap_or(bytes.len());
    // SAFETY: segment names are always ASCII; cast from &[i8] to &[u8] is safe
    // because i8 and u8 have the same size and alignment.
    let bytes: &[u8] =
        unsafe { std::slice::from_raw_parts(bytes.as_ptr() as *const u8, len) };
    std::str::from_utf8(bytes).unwrap_or("")
}
