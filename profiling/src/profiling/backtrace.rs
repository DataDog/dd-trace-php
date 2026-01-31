use crate::profiling::stack_walking::ZendFrame;
use core::ops::Deref;
use libdd_profiling::profiles::collections::Arc;
use libdd_profiling::profiles::datatypes::ProfilesDictionary;
use std::fmt;

pub struct Backtrace {
    frames: Vec<ZendFrame>,
    profiles_dictionary: Arc<ProfilesDictionary>,
}

impl Backtrace {
    pub fn new(frames: Vec<ZendFrame>, profiles_dictionary: Arc<ProfilesDictionary>) -> Self {
        Self {
            frames,
            profiles_dictionary,
        }
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

    pub fn profiles_dictionary(&self) -> &Arc<ProfilesDictionary> {
        &self.profiles_dictionary
    }
}

impl Deref for Backtrace {
    type Target = [ZendFrame];

    fn deref(&self) -> &Self::Target {
        self.frames.as_slice()
    }
}

impl fmt::Debug for Backtrace {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Backtrace")
            .field("frames", &self.frames)
            .finish()
    }
}
