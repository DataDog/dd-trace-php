//! Pure size-class calculations for the Zend MM layout.
//!
//! These return allocation block sizes, not Zend MM's backing chunk size.
//! They model non-debug allocations using the full bin table, including the
//! 8-byte bin. Heap-protection minimums and custom allocators are deliberately
//! not modeled, but debug support could be added in the future.

#[cfg(target_os = "windows")]
compile_error!(
    "This profiler module does not support Windows: Zend MM uses different huge-allocation rounding there."
);

pub const SMALL_MAX: usize = 3072;
pub const PAGE_SIZE: usize = 4096;
pub const CHUNK_SIZE: usize = 2 * 1024 * 1024;
/// Largest request handled by PHP's regular page allocator.
/// Larger requests use platform-specific huge-allocation rounding.
pub const LARGE_MAX: usize = CHUNK_SIZE - PAGE_SIZE;

/// Returns the small-bin size.
/// A zero-byte request maps to the 8-byte bin.
///
/// Precondition: `raw_size <= SMALL_MAX`.
pub const fn small_allocation_size(raw_size: usize) -> usize {
    if raw_size == 0 {
        return 8;
    }
    let spacing = if raw_size <= 64 {
        8
    } else {
        let bit_length = usize::BITS - (raw_size - 1).leading_zeros();
        1usize << (bit_length - 3)
    };
    round_up(raw_size, spacing)
}

/// Returns the page-rounded size for a large allocation.
///
/// Precondition: `SMALL_MAX < raw_size <= LARGE_MAX`.
pub const fn large_allocation_size(raw_size: usize) -> usize {
    debug_assert!(raw_size > SMALL_MAX);
    debug_assert!(raw_size <= LARGE_MAX);
    round_up(raw_size, PAGE_SIZE)
}

/// Returns the rounded size for a huge allocation.
///
/// Preconditions: `raw_size > LARGE_MAX`, `page_size` is a nonzero power of
/// two, and the rounded size fits in `usize`.
pub const fn huge_allocation_size(raw_size: usize, page_size: usize) -> usize {
    debug_assert!(raw_size > LARGE_MAX);
    debug_assert!(page_size.is_power_of_two());
    debug_assert!(raw_size <= usize::MAX - (page_size - 1));
    round_up(raw_size, page_size)
}

/// Selects the appropriate class and returns its allocation size.
///
/// `os_page_size` is the OS page size used for huge allocations.
/// Precondition: `os_page_size` is a nonzero power of two, for huge sizes.
/// Returns `None` if rounding a huge allocation would overflow.
pub const fn allocation_size(raw_size: usize, os_page_size: usize) -> Option<usize> {
    if raw_size <= SMALL_MAX {
        Some(small_allocation_size(raw_size))
    } else if raw_size <= LARGE_MAX {
        Some(large_allocation_size(raw_size))
    } else if raw_size <= usize::MAX - (os_page_size - 1) {
        Some(huge_allocation_size(raw_size, os_page_size))
    } else {
        None
    }
}

const fn round_up(size: usize, alignment: usize) -> usize {
    let mask = alignment.wrapping_sub(1);
    size.wrapping_add(mask) & !mask
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_small_request_matches_php_bin_table() {
        let bins = [
            8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384, 448,
            512, 640, 768, 896, 1024, 1280, 1536, 1792, 2048, 2560, 3072,
        ];
        for raw in 0..=SMALL_MAX {
            let expected = bins.iter().copied().find(|&bin| bin >= raw);
            assert_eq!(Some(small_allocation_size(raw)), expected, "request {raw}");
            assert_eq!(allocation_size(raw, PAGE_SIZE), expected);
        }
    }

    #[test]
    fn class_and_page_boundaries() {
        for raw in SMALL_MAX + 1..=LARGE_MAX {
            let expected = Some(raw.div_ceil(PAGE_SIZE) * PAGE_SIZE);
            assert_eq!(Some(large_allocation_size(raw)), expected);
            assert_eq!(allocation_size(raw, PAGE_SIZE), expected);
        }
        assert_eq!(allocation_size(LARGE_MAX + 1, PAGE_SIZE), Some(CHUNK_SIZE));
        assert_eq!(
            allocation_size(CHUNK_SIZE + 1, PAGE_SIZE),
            Some(CHUNK_SIZE + PAGE_SIZE)
        );
        assert_eq!(
            allocation_size(CHUNK_SIZE + 1, 16384),
            Some(CHUNK_SIZE + 16384)
        );
        assert_eq!(
            allocation_size(CHUNK_SIZE + 1, CHUNK_SIZE),
            Some(2 * CHUNK_SIZE)
        );
    }

    #[test]
    fn huge_allocation_overflow() {
        for alignment in [1, PAGE_SIZE, 16384, CHUNK_SIZE, 1usize << (usize::BITS - 1)] {
            let last = usize::MAX & !(alignment - 1);
            assert_eq!(huge_allocation_size(last, alignment), last);
            assert_eq!(allocation_size(last, alignment), Some(last));
            if alignment > 1 {
                assert_eq!(allocation_size(last + 1, alignment), None);
                assert_eq!(allocation_size(usize::MAX, alignment), None);
            }
        }
        let largest_aligned = usize::MAX & !(PAGE_SIZE - 1);
        assert_eq!(
            allocation_size(largest_aligned, PAGE_SIZE),
            Some(largest_aligned)
        );
    }
}
