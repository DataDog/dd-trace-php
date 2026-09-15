// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.

use object::macho::MachHeader64;
use object::read::archive::ArchiveFile;
use object::read::macho::{LoadCommandVariant, MachHeader};
use object::{
    Endian, Endianness, File, FileKind, Object, ObjectSection, ObjectSymbol, SymbolFlags,
};
use sidecar_mockgen::weaken_object_symbols;
use std::collections::HashSet;
use std::fs;
use std::path::Path;
use std::process;

fn symbol_name(name: &str) -> &str {
    #[cfg(target_os = "macos")]
    return name.strip_prefix('_').unwrap_or(name);
    #[cfg(not(target_os = "macos"))]
    return name;
}

fn is_definition(symbol: &object::Symbol<'_, '_>) -> bool {
    symbol.is_definition()
        || matches!(symbol.flags(), SymbolFlags::Elf { st_info, .. } if st_info & 0xf == 10)
}

fn php_symbols(binary: &Path) -> Result<HashSet<String>, String> {
    let data = fs::read(binary).map_err(|e| format!("read {}: {e}", binary.display()))?;
    let file =
        File::parse(data.as_slice()).map_err(|e| format!("parse {}: {e}", binary.display()))?;
    Ok(file
        .dynamic_symbols()
        .filter(is_definition)
        .filter_map(|symbol| symbol.name().ok().map(symbol_name).map(str::to_owned))
        .collect())
}

fn referenced_symbol_indices(file: &File<'_>, binary_symbols: &HashSet<String>) -> Vec<usize> {
    file.symbols()
        .filter(|symbol| {
            symbol.is_undefined()
                && !symbol.is_weak()
                && symbol
                    .name()
                    .is_ok_and(|name| binary_symbols.contains(symbol_name(name)))
        })
        .map(|symbol| symbol.index().0)
        .collect()
}

fn macho_symtab_offset(data: &[u8]) -> Result<(usize, bool), String> {
    let header = MachHeader64::<Endianness>::parse(data, 0)
        .map_err(|e| format!("parse Mach-O header: {e}"))?;
    let endian = header
        .endian()
        .map_err(|e| format!("parse Mach-O endianness: {e}"))?;
    let mut commands = header
        .load_commands(endian, data, 0)
        .map_err(|e| format!("parse Mach-O load commands: {e}"))?;
    loop {
        match commands.next() {
            Ok(Some(command)) => {
                if let Ok(LoadCommandVariant::Symtab(symtab)) = command.variant() {
                    return Ok((symtab.symoff.get(endian) as usize, endian.is_big_endian()));
                }
            }
            Ok(None) => return Err("Mach-O archive member has no LC_SYMTAB".to_string()),
            Err(e) => return Err(format!("parse Mach-O load command: {e}")),
        }
    }
}

fn weaken_archive_symbols(target: &Path, binary: &Path) -> Result<(), String> {
    let binary_symbols = php_symbols(binary)?;
    let mut data =
        fs::read(target).map_err(|e| format!("read archive {}: {e}", target.display()))?;
    let mut byte_patches = Vec::new();
    let mut word_patches = Vec::new();

    {
        let archive = ArchiveFile::parse(data.as_slice())
            .map_err(|e| format!("parse archive {}: {e}", target.display()))?;
        for member in archive.members() {
            let member = member.map_err(|e| format!("parse archive member: {e}"))?;
            let (member_offset, _) = member.file_range();
            let member_data = member
                .data(data.as_slice())
                .map_err(|e| format!("read archive member: {e}"))?;
            let Ok(kind) = FileKind::parse(member_data) else {
                continue;
            };
            let Ok(file) = File::parse(member_data) else {
                continue;
            };
            let indices = referenced_symbol_indices(&file, &binary_symbols);
            if indices.is_empty() {
                continue;
            }

            match kind {
                FileKind::Elf64 => {
                    let symtab = file
                        .section_by_name(".symtab")
                        .ok_or_else(|| "ELF archive member has no .symtab".to_string())?;
                    let (symtab_offset, _) = symtab.file_range().ok_or_else(|| {
                        "ELF archive member .symtab has no file range".to_string()
                    })?;
                    byte_patches.extend(indices.into_iter().map(|index| {
                        member_offset as usize + symtab_offset as usize + index * 24 + 4
                    }));
                }
                FileKind::MachO64 => {
                    let (symtab_offset, big_endian) = macho_symtab_offset(member_data)?;
                    word_patches.extend(indices.into_iter().map(|index| {
                        (
                            member_offset as usize + symtab_offset + index * 16 + 6,
                            big_endian,
                        )
                    }));
                }
                _ => {}
            }
        }
    }

    if byte_patches.is_empty() && word_patches.is_empty() {
        return Ok(());
    }
    for offset in byte_patches {
        let old = data[offset];
        data[offset] = (2u8 << 4) | (old & 0xf); // ELF STB_WEAK = 2
    }
    for (offset, big_endian) in word_patches {
        let bytes: [u8; 2] = data[offset..offset + 2]
            .try_into()
            .map_err(|_| "truncated Mach-O symbol table".to_string())?;
        let old = if big_endian {
            u16::from_be_bytes(bytes)
        } else {
            u16::from_le_bytes(bytes)
        };
        let new = old | 0x0040; // Mach-O N_WEAK_REF
        let bytes = if big_endian {
            new.to_be_bytes()
        } else {
            new.to_le_bytes()
        };
        data[offset..offset + 2].copy_from_slice(&bytes);
    }
    fs::write(target, data).map_err(|e| format!("write archive {}: {e}", target.display()))
}

fn weaken_target(target: &Path, binary: &Path) -> Result<(), String> {
    let data = fs::read(target).map_err(|e| format!("read {}: {e}", target.display()))?;
    match FileKind::parse(data.as_slice()) {
        Ok(FileKind::Archive) => weaken_archive_symbols(target, binary),
        _ => weaken_object_symbols(target, binary),
    }
}

fn main() {
    let args: Vec<_> = std::env::args_os().collect();

    if args.get(1).and_then(|a| a.to_str()) != Some("weaken-dynsym") || args.len() < 4 {
        eprintln!("Usage: php_sidecar_mockgen weaken-dynsym <target.o|target.a ...> <php_binary>");
        process::exit(1);
    }

    let php_binary = Path::new(args.last().unwrap());
    for target in &args[2..args.len() - 1] {
        let target = Path::new(target);
        if let Err(e) = weaken_target(target, php_binary) {
            eprintln!("Warning: weaken-dynsym {}: {e}", target.display());
            process::exit(1);
        }
    }
}
