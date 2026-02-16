use crate::profiling::stack_walking::ZendFrame;
use core::ops::Deref;

#[derive(Debug)]
pub struct Backtrace {
    frames: Vec<ZendFrame>,
}

impl Backtrace {
    pub const fn new(frames: Vec<ZendFrame>) -> Self {
        Self { frames }
    }

    pub fn len(&self) -> usize {
        self.frames.len()
    }

    pub fn is_empty(&self) -> bool {
        self.frames.is_empty()
    }

    pub fn iter(&self) -> impl Iterator<Item = &ZendFrame> {
        self.frames.iter()
    }
}

impl Deref for Backtrace {
    type Target = [ZendFrame];

    fn deref(&self) -> &Self::Target {
        self.frames.as_slice()
    }
}
