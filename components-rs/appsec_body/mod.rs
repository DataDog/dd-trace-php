//! Truncation-tolerant JSON and XML request body parsers for AppSec.
//!
//! A parse returns a tape: a flat encoding of the resulting PHP value, which
//! the extension turns into zvals after Rust has returned. Building zvals may
//! bail out (longjmp) on memory exhaustion, and that must never cross Rust
//! frames.
//!
//! Each record starts with an [`AppsecBodyRecord`] byte. `Key` and `Str` are
//! followed by a native-endian `usize` length and the bytes, `Int` by a
//! native-endian `i64`, `Float` by a native-endian `f64`. Containers are
//! closed by `End`, and maps alternate `Key` records with values.

use libdd_common_ffi::slice::{AsBytes, CharSlice};
use std::mem::ManuallyDrop;

mod json;
mod xml;

/// Written as a single byte.
#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AppsecBodyRecord {
    MapStart = 1,
    ListStart,
    End,
    Key,
    Str,
    Int,
    Float,
    True,
    False,
    Null,
}

#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AppsecBodyStatus {
    /// Nothing usable was parsed; the tape is empty.
    Empty,
    /// The input was truncated or malformed; the tape holds what preceded the
    /// error, with all containers closed.
    Partial,
    Complete,
}

/// Owned by Rust; release it with `ddog_appsec_body_tape_drop`.
#[repr(C)]
pub struct AppsecBodyTape {
    pub ptr: *mut u8,
    pub len: usize,
    pub capacity: usize,
    pub status: AppsecBodyStatus,
}

#[derive(Default)]
struct TapeWriter {
    buf: Vec<u8>,
    open: usize,
}

impl TapeWriter {
    fn record(&mut self, record: AppsecBodyRecord) {
        self.buf.push(record as u8);
    }

    fn map_start(&mut self) {
        self.record(AppsecBodyRecord::MapStart);
        self.open += 1;
    }

    fn list_start(&mut self) {
        self.record(AppsecBodyRecord::ListStart);
        self.open += 1;
    }

    fn end(&mut self) {
        self.record(AppsecBodyRecord::End);
        self.open -= 1;
    }

    fn bytes(&mut self, record: AppsecBodyRecord, bytes: &[u8]) {
        self.record(record);
        self.buf.extend_from_slice(&bytes.len().to_ne_bytes());
        self.buf.extend_from_slice(bytes);
    }

    fn key(&mut self, key: &[u8]) {
        self.bytes(AppsecBodyRecord::Key, key);
    }

    fn str(&mut self, value: &[u8]) {
        self.bytes(AppsecBodyRecord::Str, value);
    }

    fn int(&mut self, value: i64) {
        self.record(AppsecBodyRecord::Int);
        self.buf.extend_from_slice(&value.to_ne_bytes());
    }

    fn float(&mut self, value: f64) {
        self.record(AppsecBodyRecord::Float);
        self.buf.extend_from_slice(&value.to_ne_bytes());
    }

    fn bool(&mut self, value: bool) {
        self.record(if value {
            AppsecBodyRecord::True
        } else {
            AppsecBodyRecord::False
        });
    }

    fn null(&mut self) {
        self.record(AppsecBodyRecord::Null);
    }

    fn mark(&self) -> usize {
        self.buf.len()
    }

    /// Discards records written since `mark`, which must not include
    /// container starts.
    fn rollback(&mut self, mark: usize) {
        self.buf.truncate(mark);
    }

    fn finish(mut self, complete: bool) -> AppsecBodyTape {
        while self.open > 0 {
            self.end();
        }
        let status = if self.buf.is_empty() {
            AppsecBodyStatus::Empty
        } else if complete {
            AppsecBodyStatus::Complete
        } else {
            AppsecBodyStatus::Partial
        };
        let mut buf = ManuallyDrop::new(self.buf);
        AppsecBodyTape {
            ptr: buf.as_mut_ptr(),
            len: buf.len(),
            capacity: buf.capacity(),
            status,
        }
    }
}

/// A negative depth means unlimited.
fn depth_limit(max_depth: i32) -> u32 {
    u32::try_from(max_depth).unwrap_or(u32::MAX)
}

/// Parses a possibly truncated JSON document. Containers nested deeper than
/// `max_depth` are kept empty.
#[no_mangle]
pub extern "C" fn ddog_appsec_parse_json(input: CharSlice, max_depth: i32) -> AppsecBodyTape {
    json::parse(input.as_bytes(), depth_limit(max_depth))
}

/// Parses a possibly truncated XML document into
/// `{tag: [{"@attr": value, ...}, "text", {child...}, ...]}`. The content of
/// elements nested deeper than `max_depth` is dropped. External entities are
/// never loaded.
#[no_mangle]
pub extern "C" fn ddog_appsec_parse_xml(input: CharSlice, max_depth: i32) -> AppsecBodyTape {
    xml::parse(input.as_bytes(), depth_limit(max_depth))
}

/// # Safety
/// `tape` must come from one of the parse functions and not be dropped twice.
#[no_mangle]
pub unsafe extern "C" fn ddog_appsec_body_tape_drop(tape: AppsecBodyTape) {
    if !tape.ptr.is_null() {
        drop(Vec::from_raw_parts(tape.ptr, tape.len, tape.capacity));
    }
}

/// Renders a tape as compact JSON-like text, preserving key order.
#[cfg(test)]
fn render(tape: AppsecBodyTape) -> (AppsecBodyStatus, String) {
    fn take<'a>(buf: &'a [u8], pos: &mut usize, n: usize) -> &'a [u8] {
        let slice = &buf[*pos..*pos + n];
        *pos += n;
        slice
    }
    fn text(buf: &[u8], pos: &mut usize) -> String {
        let len = usize::from_ne_bytes(take(buf, pos, 8).try_into().unwrap());
        format!("{:?}", String::from_utf8_lossy(take(buf, pos, len)))
    }

    let status = tape.status;
    let buf = unsafe { Vec::from_raw_parts(tape.ptr, tape.len, tape.capacity) };
    let mut out = String::new();
    // Per open container: whether it is a map, and whether it has items yet.
    let mut stack: Vec<(bool, bool)> = Vec::new();
    let mut pos = 0;
    while pos < buf.len() {
        let tag = take(&buf, &mut pos, 1)[0];
        if tag == AppsecBodyRecord::End as u8 {
            let (is_map, _) = stack.pop().unwrap();
            out.push(if is_map { '}' } else { ']' });
            continue;
        }
        if tag == AppsecBodyRecord::Key as u8 {
            let (_, has_items) = stack.last_mut().unwrap();
            if *has_items {
                out.push(',');
            }
            *has_items = true;
            out.push_str(&text(&buf, &mut pos));
            out.push(':');
            continue;
        }
        if let Some((false, has_items)) = stack.last_mut() {
            if *has_items {
                out.push(',');
            }
            *has_items = true;
        }
        match tag {
            t if t == AppsecBodyRecord::MapStart as u8 => {
                out.push('{');
                stack.push((true, false));
            }
            t if t == AppsecBodyRecord::ListStart as u8 => {
                out.push('[');
                stack.push((false, false));
            }
            t if t == AppsecBodyRecord::Str as u8 => out.push_str(&text(&buf, &mut pos)),
            t if t == AppsecBodyRecord::Int as u8 => {
                let value = i64::from_ne_bytes(take(&buf, &mut pos, 8).try_into().unwrap());
                out.push_str(&value.to_string());
            }
            t if t == AppsecBodyRecord::Float as u8 => {
                let value = f64::from_ne_bytes(take(&buf, &mut pos, 8).try_into().unwrap());
                out.push_str(&format!("{value:?}"));
            }
            t if t == AppsecBodyRecord::True as u8 => out.push_str("true"),
            t if t == AppsecBodyRecord::False as u8 => out.push_str("false"),
            t if t == AppsecBodyRecord::Null as u8 => out.push_str("null"),
            t => panic!("unknown record {t}"),
        }
    }
    assert!(stack.is_empty(), "unclosed containers in {out}");
    (status, out)
}
