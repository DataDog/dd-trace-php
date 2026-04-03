use crate::profiling::stack_walking::IrLocation;
use core::ops::Deref;

#[derive(Debug)]
pub struct Backtrace {
    frames: Vec<IrLocation>,
}

impl Backtrace {
    pub const fn new(frames: Vec<IrLocation>) -> Self {
        Self { frames }
    }

    pub fn len(&self) -> usize {
        self.frames.len()
    }

    pub fn is_empty(&self) -> bool {
        self.frames.is_empty()
    }

    pub fn iter(&self) -> impl Iterator<Item = &IrLocation> {
        self.frames.iter()
    }
}

impl Deref for Backtrace {
    type Target = [IrLocation];

    fn deref(&self) -> &Self::Target {
        self.frames.as_slice()
    }
}
