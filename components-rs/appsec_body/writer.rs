use crate::{AppsecBodyMessagePack, AppsecBodyStatus};

#[derive(Default)]
pub(crate) struct MsgpackWriter {
    buf: Vec<u8>,
    open: Vec<Container>,
    failed: bool,
}

struct Container {
    count_offset: usize,
    count: u32,
    is_map: bool,
    has_key: bool,
    key_offset: usize,
}

impl MsgpackWriter {
    fn value(&mut self) {
        if self.failed {
            return;
        }
        if let Some(parent) = self.open.last_mut() {
            if parent.is_map {
                debug_assert!(parent.has_key);
                parent.has_key = false;
            }
            if let Some(count) = parent.count.checked_add(1) {
                parent.count = count;
            } else {
                self.failed = true;
            }
        }
    }

    pub(crate) fn map_start(&mut self) {
        self.start(0xdf, true);
    }

    pub(crate) fn list_start(&mut self) {
        self.start(0xdd, false);
    }

    fn start(&mut self, tag: u8, is_map: bool) {
        self.value();
        if self.failed || !self.reserve(5) {
            self.failed = true;
            return;
        }
        self.open.reserve(1);
        self.buf.push(tag);
        let count_offset = self.buf.len();
        self.buf.extend_from_slice(&[0; 4]);
        self.open.push(Container {
            count_offset,
            count: 0,
            is_map,
            has_key: false,
            key_offset: 0,
        });
    }

    pub(crate) fn end(&mut self) {
        if self.failed {
            return;
        }
        let container = self.open.pop().expect("unbalanced MessagePack container");
        if container.has_key {
            self.buf.truncate(container.key_offset);
        }
        self.buf[container.count_offset..container.count_offset + 4]
            .copy_from_slice(&container.count.to_be_bytes());
    }

    fn write_str(&mut self, bytes: &[u8]) {
        let Ok(len) = u32::try_from(bytes.len()) else {
            self.failed = true;
            return;
        };
        if !self.reserve(5 + bytes.len()) {
            return;
        }
        self.buf.push(0xdb);
        self.buf.extend_from_slice(&len.to_be_bytes());
        self.buf.extend_from_slice(bytes);
    }

    pub(crate) fn key(&mut self, key: &[u8]) {
        if self.failed {
            return;
        }
        let parent = self.open.last_mut().expect("key outside map");
        debug_assert!(parent.is_map && !parent.has_key);
        parent.has_key = true;
        parent.key_offset = self.buf.len();
        self.write_str(key);
    }

    pub(crate) fn str(&mut self, value: &[u8]) {
        self.value();
        self.write_str(value);
    }

    pub(crate) fn int(&mut self, value: i64) {
        self.value();
        if !self.reserve(9) {
            return;
        }
        self.buf.push(0xd3);
        self.buf.extend_from_slice(&value.to_be_bytes());
    }

    pub(crate) fn float(&mut self, value: f64) {
        self.value();
        if !self.reserve(9) {
            return;
        }
        self.buf.push(0xcb);
        self.buf.extend_from_slice(&value.to_bits().to_be_bytes());
    }

    pub(crate) fn bool(&mut self, value: bool) {
        self.value();
        if !self.reserve(1) {
            return;
        }
        self.buf.push(if value { 0xc3 } else { 0xc2 });
    }

    pub(crate) fn null(&mut self) {
        self.value();
        if !self.reserve(1) {
            return;
        }
        self.buf.push(0xc0);
    }

    fn reserve(&mut self, additional: usize) -> bool {
        if self.failed {
            return false;
        }
        self.buf.reserve(additional);
        true
    }

    pub(crate) fn mark(&self) -> usize {
        self.buf.len()
    }

    /// Removes the last value, which started at `mark`, and its key if any.
    pub(crate) fn discard_last(&mut self, mark: usize) {
        if self.failed {
            return;
        }
        self.buf.truncate(mark);
        if let Some(parent) = self.open.last_mut() {
            parent.count -= 1;
            // The key is still at key_offset; end() truncates it.
            parent.has_key = parent.is_map;
        }
    }

    pub(crate) fn finish(mut self, complete: bool) -> AppsecBodyMessagePack {
        while !self.failed && !self.open.is_empty() {
            self.end();
        }
        let status = if self.failed || self.buf.is_empty() {
            AppsecBodyStatus::Empty
        } else if complete {
            AppsecBodyStatus::Complete
        } else {
            AppsecBodyStatus::Partial
        };
        if self.failed {
            self.buf.clear();
        }
        let mut buf = std::mem::ManuallyDrop::new(self.buf);
        let (ptr, len, capacity) = (buf.as_mut_ptr(), buf.len(), buf.capacity());
        AppsecBodyMessagePack {
            ptr: if capacity == 0 {
                std::ptr::null_mut()
            } else {
                ptr
            },
            len,
            capacity,
            free: None,
            status,
        }
    }
}
