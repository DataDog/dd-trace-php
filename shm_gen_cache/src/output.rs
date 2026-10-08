//! Lookup output buffers.

/// A fixed logical byte capacity backed by `WORDS` complete words, so
/// lookups can copy whole words. The padding is storage only and is not
/// part of the byte view.
///
/// Stable Rust cannot size an array as `ceil(capacity / 8)`, so the word
/// count is the type parameter and the logical capacity a value; the
/// [`output_buffer!`](crate::output_buffer) macro computes the former from
/// the latter.
#[repr(C, align(8))]
#[derive(Clone, Debug)]
pub struct OutputBuffer<const WORDS: usize> {
    words: [u64; WORDS],
    capacity: usize,
}

impl<const WORDS: usize> OutputBuffer<WORDS> {
    /// A zeroed buffer of `capacity` logical bytes; `capacity` must not
    /// exceed `8 * WORDS`.
    pub const fn new(capacity: usize) -> Self {
        assert!(
            capacity <= 8 * WORDS,
            "output buffer capacity exceeds its words"
        );
        OutputBuffer {
            words: [0; WORDS],
            capacity,
        }
    }

    /// The logical capacity in bytes.
    #[inline(always)]
    pub const fn capacity(&self) -> usize {
        self.capacity
    }

    /// The backing words (including padding).
    #[inline(always)]
    pub fn words(&self) -> &[u64; WORDS] {
        &self.words
    }

    /// The backing words (including padding).
    #[inline(always)]
    pub fn words_mut(&mut self) -> &mut [u64; WORDS] {
        &mut self.words
    }

    /// The logical bytes.
    #[inline(always)]
    pub fn bytes(&self) -> &[u8] {
        // SAFETY: capacity <= 8 * WORDS bytes of initialised words.
        unsafe { core::slice::from_raw_parts(self.words.as_ptr().cast(), self.capacity) }
    }
}

/// `output_buffer!(N)`: an [`OutputBuffer`] of `N` logical bytes.
#[macro_export]
macro_rules! output_buffer {
    ($capacity:expr) => {
        $crate::OutputBuffer::<{ usize::div_ceil($capacity, 8) }>::new($capacity)
    };
}
