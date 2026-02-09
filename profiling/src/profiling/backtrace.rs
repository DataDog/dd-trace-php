use crate::profiling::stack_walking::Frame;
use core::ops::Deref;

#[derive(Debug)]
pub struct Backtrace {
    frames: Vec<Frame>,
}

impl Backtrace {
    pub const fn new(frames: Vec<Frame>) -> Self {
        Self { frames }
    }

    pub fn len(&self) -> usize {
        self.frames.len()
    }

    pub fn is_empty(&self) -> bool {
        self.frames.is_empty()
    }

    pub fn iter(&self) -> impl Iterator<Item = &Frame> {
        self.frames.iter()
    }
}

impl Deref for Backtrace {
    type Target = [Frame];

    fn deref(&self) -> &Self::Target {
        self.frames.as_slice()
    }
}
