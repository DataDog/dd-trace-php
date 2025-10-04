// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0 OR BSD-3-Clause

use crate::bitset::BitSet;
use crate::config::SystemSettings;
use crate::inlinevec::InlineVec;
use crate::profiling::{PhpUpscalingRule, ValueType};
use datadog_profiling::profiles::PoissonUpscalingRule;
use std::fmt::Debug;
use std::hash::{Hash, Hasher};
use std::mem::MaybeUninit;
use std::num::NonZeroU64;
use std::slice;
use std::sync::atomic::{AtomicU32, AtomicU64, Ordering};

pub const MAX_SAMPLE_TYPES_PER_PROFILE: usize = 2;
pub const MAX_SAMPLE_VALUES: usize = 23;

// todo: use core::mem::variant_count when it stabilizes, structure things
//       around SampleDiscriminant.
pub const NUM_PROFILE_TYPES: usize = 13;

pub type ProfileType = InlineVec<ValueType, MAX_SAMPLE_TYPES_PER_PROFILE>;

/// The profile types in PHP. The [`SampleValue`] enum must have its variants
/// in the same order as they come in this list.
pub const PROFILE_TYPES: [ProfileType; NUM_PROFILE_TYPES] = [
    SAMPLE_TYPE_WALL_TIME,
    SAMPLE_TYPE_CPU_TIME,
    SAMPLE_TYPE_ALLOC,
    SAMPLE_TYPE_TIMELINE,
    SAMPLE_TYPE_EXCEPTION,
    SAMPLE_TYPE_SOCKET_READ_TIME,
    SAMPLE_TYPE_SOCKET_WRITE_TIME,
    SAMPLE_TYPE_FILE_IO_READ_TIME,
    SAMPLE_TYPE_FILE_IO_WRITE_TIME,
    SAMPLE_TYPE_SOCKET_READ_SIZE,
    SAMPLE_TYPE_SOCKET_WRITE_SIZE,
    SAMPLE_TYPE_FILE_IO_READ_SIZE,
    SAMPLE_TYPE_FILE_IO_WRITE_SIZE,
];

/// The sample values for a given profile type.
///
/// The repr(u8) is valid even though this holds data larger than u8; see the
/// documentation on primitive representations:
/// https://doc.rust-lang.org/reference/type-layout.html#primitive-representations
///
/// If the order of the enum is changed, or if variants are added or removed,
/// then [`PROFILE_TYPES`] needs to be changed (or vice versa).
#[derive(Clone, Copy, Debug, Eq, Hash, PartialEq)]
#[repr(u8)]
pub enum SampleValue {
    WallTime { count: i64, nanoseconds: i64 },
    CpuTime { nanoseconds: i64 },
    Alloc { count: i64, bytes: i64 },
    Timeline { nanoseconds: i64 },
    Exception { count: i64 },
    FileIoReadTime { nanoseconds: i64, count: i64 },
    FileIoWriteTime { nanoseconds: i64, count: i64 },
    FileIoReadSize { bytes: i64, count: i64 },
    FileIoWriteSize { bytes: i64, count: i64 },
    SocketReadTime { nanoseconds: i64, count: i64 },
    SocketWriteTime { nanoseconds: i64, count: i64 },
    SocketReadSize { bytes: i64, count: i64 },
    SocketWriteSize { bytes: i64, count: i64 },
}

// Must have same order as SampleValue (there's a test for this, update it
// if you add/remove members).
#[derive(Clone, Copy, Debug, Eq, Hash, PartialEq)]
#[repr(u8)]
pub enum SampleDiscriminant {
    WallTime,
    CpuTime,
    Alloc,
    Timeline,
    Exception,
    FileIoReadTime,
    FileIoWriteTime,
    FileIoReadSize,
    FileIoWriteSize,
    SocketReadTime,
    SocketWriteTime,
    SocketReadSize,
    SocketWriteSize,
}

/// Tracks which profile types are enabled. Since there are 1 or 2 sample
/// types per profile, it also keeps a bitset for which sample types and
/// values are in-use. So for 13 profiles, there may be 13-26 sample types.
#[derive(Clone, Debug)]
pub struct EnabledProfiles {
    /// Tracks which profile types are enabled.
    profiles: BitSet,
    /// Tracks which sample types/values are enabled. This is the same
    /// information as the other bitset, just formatted for a different use
    /// case. This means this field isn't used in Eq + Hash.
    samples: BitSet,
}

/// IMPORTANT: must be transmutable to/from SampleValue! See:
/// https://doc.rust-lang.org/reference/type-layout.html#primitive-representation-of-enums-with-fields
#[repr(C)]
struct RestructuredSample {
    discriminant: u8,
    value: (i64, MaybeUninit<i64>),
}

impl Eq for EnabledProfiles {}
impl PartialEq for EnabledProfiles {
    fn eq(&self, other: &Self) -> bool {
        self.profiles == other.profiles
    }
}
impl Hash for EnabledProfiles {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.profiles.hash(state);
    }
}

impl EnabledProfiles {
    pub fn new(config: &SystemSettings) -> EnabledProfiles {
        let wall_time = config.profiling_enabled;
        let cpu_time = config.profiling_experimental_cpu_time_enabled;
        let alloc = config.profiling_allocation_enabled;
        let timeline = config.profiling_timeline_enabled;
        let exception = config.profiling_exception_enabled;
        let io_profiling = cfg!(feature = "io_profiling") && config.profiling_io_enabled;

        // This implementation is tied to the order PROFILE_TYPES is defined.
        let profiles_mask = [
            wall_time,
            cpu_time,
            alloc,
            timeline,
            exception,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
        ];

        let profiles = BitSet::from_iter(
            profiles_mask
                .into_iter()
                .enumerate()
                .filter_map(|(offset, enabled)| enabled.then_some(offset)),
        );

        // This implementation is tied to the order of SampleValues.
        let samples_mask: [bool; MAX_SAMPLE_VALUES] = [
            wall_time,
            wall_time,
            cpu_time,
            alloc,
            alloc,
            timeline,
            exception,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
            io_profiling,
        ];

        let samples = BitSet::from_iter(
            samples_mask
                .into_iter()
                .enumerate()
                .filter_map(|(offset, enabled)| enabled.then_some(offset)),
        );

        EnabledProfiles { profiles, samples }
    }
}

impl EnabledProfiles {
    /// Returns the number of profiles that are enabled.
    #[inline]
    pub fn num_enabled_profiles(&self) -> usize {
        self.profiles.len()
    }

    /// Returns the number of sample types that are enabled. This could be
    /// used to reserve space in a container before using [`Self::filter`].
    #[inline]
    pub fn num_enabled_sample_types(&self) -> usize {
        self.samples.len()
    }

    pub fn contains_profile(&self, discriminant: SampleDiscriminant) -> bool {
        self.profiles.contains(discriminant.index())
    }

    pub fn enabled_profile_types(&self) -> SampleIter<impl Iterator<Item = ProfileType> + '_> {
        let len = self.num_enabled_profiles();
        let iter = PROFILE_TYPES
            .iter()
            .enumerate()
            .filter_map(|(offset, profile_type)| {
                self.profiles.contains(offset).then_some(*profile_type)
            });
        SampleIter { len, iter }
    }

    pub fn enabled_sample_types(&self) -> SampleIter<impl Iterator<Item = ValueType> + '_> {
        let len = self.num_enabled_sample_types();
        let iter = self.enabled_profile_types().flatten();
        SampleIter { len, iter }
    }
}

const SAMPLE_TYPE_WALL_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "sample", // todo: rename "wall-sample"
        unit: "count",
    },
    ValueType {
        r#type: "wall-time",
        unit: "nanoseconds",
    },
]);

const SAMPLE_TYPE_CPU_TIME: ProfileType = ProfileType::from([ValueType {
    r#type: "cpu-time",
    unit: "nanoseconds",
}]);

const SAMPLE_TYPE_ALLOC: ProfileType = ProfileType::from([
    ValueType {
        r#type: "alloc-samples",
        unit: "count",
    },
    ValueType {
        r#type: "alloc-size",
        unit: "bytes",
    },
]);

const SAMPLE_TYPE_TIMELINE: ProfileType = ProfileType::from([ValueType {
    r#type: "timeline",
    unit: "nanoseconds",
}]);

const SAMPLE_TYPE_EXCEPTION: ProfileType = ProfileType::from([ValueType {
    r#type: "exception-samples",
    unit: "count",
}]);

const SAMPLE_TYPE_SOCKET_READ_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "socket-read-time",
        unit: "nanoseconds",
    },
    ValueType {
        r#type: "socket-read-time-samples",
        unit: "count",
    },
]);

const SAMPLE_TYPE_SOCKET_WRITE_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "socket-write-time",
        unit: "nanoseconds",
    },
    ValueType {
        r#type: "socket-write-time-samples",
        unit: "count",
    },
]);

const SAMPLE_TYPE_FILE_IO_READ_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "file-io-read-time",
        unit: "nanoseconds",
    },
    ValueType {
        r#type: "file-io-read-time-samples",
        unit: "count",
    },
]);

const SAMPLE_TYPE_FILE_IO_WRITE_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "file-io-write-time",
        unit: "nanoseconds",
    },
    ValueType {
        r#type: "file-io-write-time-samples",
        unit: "count",
    },
]);

const SAMPLE_TYPE_SOCKET_READ_SIZE: ProfileType = ProfileType::from([
    ValueType {
        r#type: "socket-read-size",
        unit: "bytes",
    },
    ValueType {
        r#type: "socket-read-size-samples",
        unit: "count",
    },
]);

const SAMPLE_TYPE_SOCKET_WRITE_SIZE: ProfileType = ProfileType::from([
    ValueType {
        r#type: "socket-write-size",
        unit: "bytes",
    },
    ValueType {
        r#type: "socket-write-size-samples",
        unit: "count",
    },
]);

const SAMPLE_TYPE_FILE_IO_READ_SIZE: ProfileType = ProfileType::from([
    ValueType {
        r#type: "file-io-read-size",
        unit: "bytes",
    },
    ValueType {
        r#type: "file-io-read-size-samples",
        unit: "count",
    },
]);

const SAMPLE_TYPE_FILE_IO_WRITE_SIZE: ProfileType = ProfileType::from([
    ValueType {
        r#type: "file-io-write-size",
        unit: "bytes",
    },
    ValueType {
        r#type: "file-io-write-size-samples",
        unit: "count",
    },
]);

impl SampleValue {
    pub fn discriminant(&self) -> SampleDiscriminant {
        // SAFETY: SampleValue uses a primitive representation.
        let tag = unsafe { *(self as *const Self as *const u8) };
        unsafe { core::mem::transmute(tag) }
    }

    pub fn sample_types(&self) -> ProfileType {
        let discriminant = self.discriminant();
        let index = discriminant.index();
        debug_assert!(index < PROFILE_TYPES.len());
        // SAFETY: this cannot go out of bounds, also debug checked.
        unsafe { *PROFILE_TYPES.get_unchecked(index) }
    }

    pub fn as_slice(&self) -> &[i64] {
        // Convert the &(i64, MaybeUninit<i64>) into &[i64].
        let tuple = &RestructuredSample::from(self).value;
        let ptr = tuple as *const (_, _) as *const i64;
        let n = self.sample_types().len();
        // SAFETY: &(i64, MaybeUninit<i64>) is layout compatible with &[i64]
        // provided that the length of the slice is either 1 or 2, and lengths
        // of 2 are only used when the MaybeUninit is actually initialized.
        unsafe { slice::from_raw_parts(ptr, n) }
    }
}

#[derive(Clone, Copy, Debug, Eq, Hash, PartialEq)]
#[repr(C)]
pub enum SampleDiscriminantTryValueOfError {
    EmptyValues, // special case of MismatchedValueCount for better message
    MismatchedValueCount,
}

#[derive(Clone, Copy, Debug)]
pub struct ProfileUpscalingIntervals<'a> {
    pub alloc: &'a AtomicU64,
    pub exception: &'a AtomicU32,
    pub file_io_read_time: &'a AtomicU64,
    pub file_io_write_time: &'a AtomicU64,
    pub file_io_read_size: &'a AtomicU64,
    pub file_io_write_size: &'a AtomicU64,
    pub socket_read_time: &'a AtomicU64,
    pub socket_write_time: &'a AtomicU64,
    pub socket_read_size: &'a AtomicU64,
    pub socket_write_size: &'a AtomicU64,
}

impl SampleDiscriminant {
    pub fn index(self) -> usize {
        self as u8 as usize
    }

    // pub fn try_value_of(
    //     self,
    //     values: &[i64],
    // ) -> Result<SampleValue, SampleDiscriminantTryValueOfError> {
    //     if values.is_empty() {
    //         return Err(SampleDiscriminantTryValueOfError::EmptyValues);
    //     }
    //     // SAFETY: len of PROFILE_TYPES matches variant_count of
    //     // SampleDiscriminant, so it must be in bounds.
    //     let n_vals = unsafe { PROFILE_TYPES.get_unchecked(self.index()).len() };
    //     if n_vals != values.len() {
    //         return Err(SampleDiscriminantTryValueOfError::MismatchedValueCount);
    //     }
    //
    //     // The strategy here is to always create a valid two-element array.
    //     // If the user only provides 1 value, then the 2nd will be 0, which is
    //     // a fine value for a MaybeUninit.
    //     let mut vals = [0i64; MAX_SAMPLE_TYPES_PER_PROFILE];
    //     for (src, dst) in values.iter().zip(vals.iter_mut()) {
    //         *dst = *src;
    //     }
    //
    //     let sample = RestructuredSample {
    //         discriminant: self as u8,
    //         value: (vals[0], MaybeUninit::new(vals[1])),
    //     };
    //     Ok(unsafe { mem::transmute(sample) })
    // }

    pub fn upscaling(self, intervals: &ProfileUpscalingIntervals<'_>) -> Option<PhpUpscalingRule> {
        match self {
            SampleDiscriminant::WallTime => None,
            SampleDiscriminant::CpuTime => None,
            SampleDiscriminant::Alloc => {
                let sampling_distance = NonZeroU64::new(intervals.alloc.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    // One day we can make this less brittle with
                    // offset_of_enum, but it's not stable yet.
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 1,
                        count_offset: 0,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::Timeline => None,
            SampleDiscriminant::Exception => Some(PhpUpscalingRule::Proportional {
                scale: intervals.exception.load(Ordering::SeqCst) as f64,
            }),
            SampleDiscriminant::FileIoReadTime => {
                let sampling_distance =
                    NonZeroU64::new(intervals.file_io_read_time.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::FileIoWriteTime => {
                let sampling_distance =
                    NonZeroU64::new(intervals.file_io_write_time.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::FileIoReadSize => {
                let sampling_distance =
                    NonZeroU64::new(intervals.file_io_read_size.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::FileIoWriteSize => {
                let sampling_distance =
                    NonZeroU64::new(intervals.file_io_write_size.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::SocketReadTime => {
                let sampling_distance =
                    NonZeroU64::new(intervals.socket_read_time.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::SocketWriteTime => {
                let sampling_distance =
                    NonZeroU64::new(intervals.socket_write_time.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::SocketReadSize => {
                let sampling_distance =
                    NonZeroU64::new(intervals.socket_read_size.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
            SampleDiscriminant::SocketWriteSize => {
                let sampling_distance =
                    NonZeroU64::new(intervals.socket_write_size.load(Ordering::SeqCst));
                sampling_distance.map(|sampling_distance| {
                    PhpUpscalingRule::Poisson(PoissonUpscalingRule {
                        sum_offset: 0,
                        count_offset: 1,
                        sampling_distance,
                    })
                })
            }
        }
    }
}

pub struct SampleIter<I: Iterator> {
    len: usize,
    iter: I,
}

impl<I: Iterator> Iterator for SampleIter<I> {
    type Item = I::Item;
    fn next(&mut self) -> Option<Self::Item> {
        let next = self.iter.next();
        if next.is_some() {
            self.len -= 1;
        }
        next
    }

    fn size_hint(&self) -> (usize, Option<usize>) {
        let len = self.len();
        (len, Some(len))
    }
}

impl<I: Iterator> ExactSizeIterator for SampleIter<I> {
    fn len(&self) -> usize {
        self.len
    }
}

impl RestructuredSample {
    const fn from(sample: &SampleValue) -> &RestructuredSample {
        // SAFETY: same layout/repr and meaning.
        unsafe { core::mem::transmute(sample) }
    }

    #[cfg(test)]
    const fn to(&self) -> &SampleValue {
        // SAFETY: same layout/repr and meaning.
        unsafe { core::mem::transmute(self) }
    }
}

#[cfg(test)]
mod test {
    use super::*;
    // use crate::profiling::tests::{get_samples, get_system_settings};
    //
    // #[test]
    // fn with_profiling_disabled() {
    //     let mut settings = get_system_settings();
    //
    //     settings.profiling_enabled = false;
    //     settings.profiling_allocation_enabled = false;
    //     settings.profiling_experimental_cpu_time_enabled = false;
    //
    //     let enabled_profiles = EnabledProfiles::new(&settings);
    //     let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
    //     let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();
    //
    //     assert_eq!(types, Vec::<ValueType>::new());
    //     assert_eq!(values, Vec::<i64>::new());
    // }
    //
    // #[test]
    // fn with_profiling_enabled() {
    //     let mut settings = get_system_settings();
    //
    //     settings.profiling_enabled = true;
    //     settings.profiling_allocation_enabled = false;
    //     settings.profiling_experimental_cpu_time_enabled = false;
    //
    //     let enabled_profiles = EnabledProfiles::new(&settings);
    //     let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
    //     let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();
    //
    //     assert_eq!(
    //         types,
    //         vec![
    //             ValueType::new("sample", "count"),
    //             ValueType::new("wall-time", "nanoseconds"),
    //         ]
    //     );
    //     assert_eq!(values, vec![10, 20]);
    // }
    //
    // #[test]
    // fn with_cpu_time() {
    //     let mut settings = get_system_settings();
    //     settings.profiling_enabled = true;
    //     settings.profiling_allocation_enabled = false;
    //     settings.profiling_experimental_cpu_time_enabled = true;
    //
    //     let enabled_profiles = EnabledProfiles::new(&settings);
    //     let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
    //     let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();
    //
    //     assert_eq!(
    //         types,
    //         vec![
    //             ValueType::new("sample", "count"),
    //             ValueType::new("wall-time", "nanoseconds"),
    //             ValueType::new("cpu-time", "nanoseconds"),
    //         ]
    //     );
    //     assert_eq!(values, vec![10, 20, 30]);
    // }
    //
    // #[test]
    // fn filter_with_allocations() {
    //     let mut settings = get_system_settings();
    //     settings.profiling_enabled = true;
    //     settings.profiling_allocation_enabled = true;
    //     settings.profiling_experimental_cpu_time_enabled = false;
    //
    //     let enabled_profiles = EnabledProfiles::new(&settings);
    //     let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
    //     let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();
    //
    //     assert_eq!(
    //         types,
    //         vec![
    //             ValueType::new("sample", "count"),
    //             ValueType::new("wall-time", "nanoseconds"),
    //             ValueType::new("alloc-samples", "count"),
    //             ValueType::new("alloc-size", "bytes"),
    //         ]
    //     );
    //     assert_eq!(values, vec![10, 20, 40, 50]);
    // }
    //
    // #[test]
    // fn with_allocations_and_cpu_time() {
    //     let mut settings = get_system_settings();
    //     settings.profiling_enabled = true;
    //     settings.profiling_allocation_enabled = true;
    //     settings.profiling_experimental_cpu_time_enabled = true;
    //
    //     let enabled_profiles = EnabledProfiles::new(&settings);
    //     let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
    //     let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();
    //
    //     assert_eq!(
    //         types,
    //         vec![
    //             ValueType::new("sample", "count"),
    //             ValueType::new("wall-time", "nanoseconds"),
    //             ValueType::new("cpu-time", "nanoseconds"),
    //             ValueType::new("alloc-samples", "count"),
    //             ValueType::new("alloc-size", "bytes"),
    //         ]
    //     );
    //     assert_eq!(values, vec![10, 20, 30, 40, 50]);
    // }
    //
    // #[test]
    // fn with_cpu_time_and_exceptions() {
    //     let mut settings = get_system_settings();
    //     settings.profiling_enabled = true;
    //     settings.profiling_experimental_cpu_time_enabled = true;
    //     settings.profiling_exception_enabled = true;
    //
    //     let enabled_profiles = EnabledProfiles::new(&settings);
    //     let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
    //     let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();
    //
    //     assert_eq!(
    //         types,
    //         vec![
    //             ValueType::new("sample", "count"),
    //             ValueType::new("wall-time", "nanoseconds"),
    //             ValueType::new("cpu-time", "nanoseconds"),
    //             ValueType::new("exception-samples", "count"),
    //         ]
    //     );
    //     assert_eq!(values, vec![10, 20, 30, 70]);
    // }

    #[test]
    fn spot_check_cpu_sample() {
        let nanoseconds = 3200;
        let sample = SampleValue::CpuTime { nanoseconds };

        let profile_type = sample.sample_types();
        assert_eq!(
            profile_type.as_slice(),
            &[ValueType {
                r#type: "cpu-time",
                unit: "nanoseconds",
            }]
        );

        assert_eq!(sample.as_slice(), &[nanoseconds]);

        assert_eq!(sample.discriminant(), SampleDiscriminant::CpuTime);
        assert_eq!(sample.discriminant().index(), 1);
    }

    #[test]
    fn spot_check_wall_sample() {
        let count = 1;
        let nanoseconds = 3700;
        let sample = SampleValue::WallTime { count, nanoseconds };

        let profile_type = sample.sample_types();
        assert_eq!(
            profile_type.as_slice(),
            &[
                ValueType {
                    r#type: "sample",
                    unit: "count",
                },
                ValueType {
                    r#type: "wall-time",
                    unit: "nanoseconds",
                }
            ]
        );

        assert_eq!(sample.as_slice(), &[count, nanoseconds]);

        assert_eq!(sample.discriminant(), SampleDiscriminant::WallTime);
        assert_eq!(sample.discriminant().index(), 0);
    }

    #[test]
    fn discriminants_match_for_all_variants() {
        let cases = [
            (
                SampleValue::WallTime {
                    count: 0,
                    nanoseconds: 0,
                },
                SampleDiscriminant::WallTime,
            ),
            (
                SampleValue::CpuTime { nanoseconds: 0 },
                SampleDiscriminant::CpuTime,
            ),
            (
                SampleValue::Alloc { count: 0, bytes: 0 },
                SampleDiscriminant::Alloc,
            ),
            (
                SampleValue::Timeline { nanoseconds: 0 },
                SampleDiscriminant::Timeline,
            ),
            (
                SampleValue::Exception { count: 0 },
                SampleDiscriminant::Exception,
            ),
            (
                SampleValue::FileIoReadTime {
                    nanoseconds: 0,
                    count: 0,
                },
                SampleDiscriminant::FileIoReadTime,
            ),
            (
                SampleValue::FileIoWriteTime {
                    nanoseconds: 0,
                    count: 0,
                },
                SampleDiscriminant::FileIoWriteTime,
            ),
            (
                SampleValue::FileIoReadSize { bytes: 0, count: 0 },
                SampleDiscriminant::FileIoReadSize,
            ),
            (
                SampleValue::FileIoWriteSize { bytes: 0, count: 0 },
                SampleDiscriminant::FileIoWriteSize,
            ),
            (
                SampleValue::SocketReadTime {
                    nanoseconds: 0,
                    count: 0,
                },
                SampleDiscriminant::SocketReadTime,
            ),
            (
                SampleValue::SocketWriteTime {
                    nanoseconds: 0,
                    count: 0,
                },
                SampleDiscriminant::SocketWriteTime,
            ),
            (
                SampleValue::SocketReadSize { bytes: 0, count: 0 },
                SampleDiscriminant::SocketReadSize,
            ),
            (
                SampleValue::SocketWriteSize { bytes: 0, count: 0 },
                SampleDiscriminant::SocketWriteSize,
            ),
        ];

        for (value, disc) in cases {
            assert_eq!(disc, value.discriminant(), "Mismatch for {:?}", value);
        }
    }

    // "soa": structure of arrays e.g. struct { ts: [T; N], us: [U; N] }
    // "soa" array of structures e.g. [struct { t: T, u: U}; N]
    #[test]
    fn unsafe_aos_to_soa() {
        for i in 0..PROFILE_TYPES.len() {
            let src = RestructuredSample {
                discriminant: i as u8,
                // Using different values distinguishes between fields.
                // Avoid 0 because the impl initializes to 0.
                value: (1, MaybeUninit::new(2)),
            };
            match *src.to() {
                SampleValue::WallTime { count, nanoseconds } => {
                    assert_eq!(count, 1);
                    assert_eq!(nanoseconds, 2);
                }
                SampleValue::CpuTime { nanoseconds } => {
                    assert_eq!(nanoseconds, 1);
                }
                SampleValue::Alloc { count, bytes } => {
                    assert_eq!(count, 1);
                    assert_eq!(bytes, 2);
                }
                SampleValue::Timeline { nanoseconds } => {
                    assert_eq!(nanoseconds, 1);
                }
                SampleValue::Exception { count } => {
                    assert_eq!(count, 1);
                }
                SampleValue::FileIoReadTime { nanoseconds, count } => {
                    assert_eq!(nanoseconds, 1);
                    assert_eq!(count, 2);
                }
                SampleValue::FileIoWriteTime { nanoseconds, count } => {
                    assert_eq!(nanoseconds, 1);
                    assert_eq!(count, 2);
                }
                SampleValue::FileIoReadSize { bytes, count } => {
                    assert_eq!(bytes, 1);
                    assert_eq!(count, 2);
                }
                SampleValue::FileIoWriteSize { bytes, count } => {
                    assert_eq!(bytes, 1);
                    assert_eq!(count, 2);
                }
                SampleValue::SocketReadTime { nanoseconds, count } => {
                    assert_eq!(nanoseconds, 1);
                    assert_eq!(count, 2);
                }
                SampleValue::SocketWriteTime { nanoseconds, count } => {
                    assert_eq!(nanoseconds, 1);
                    assert_eq!(count, 2);
                }
                SampleValue::SocketReadSize { bytes, count } => {
                    assert_eq!(bytes, 1);
                    assert_eq!(count, 2);
                }
                SampleValue::SocketWriteSize { bytes, count } => {
                    assert_eq!(bytes, 1);
                    assert_eq!(count, 2);
                }
            }
        }
    }

    // "soa": structure of arrays e.g. struct { ts: [T; N], us: [U; N] }
    // "soa" array of structures e.g. [struct { t: T, u: U}; N]
    #[test]
    fn unsafe_soa_to_soa() {
        const T: i64 = 1;
        const U: i64 = 2;
        let cases = [
            SampleValue::WallTime {
                count: T,
                nanoseconds: U,
            },
            SampleValue::CpuTime { nanoseconds: T },
            SampleValue::Alloc { count: T, bytes: U },
            SampleValue::Timeline { nanoseconds: T },
            SampleValue::Exception { count: T },
            SampleValue::FileIoReadTime {
                nanoseconds: T,
                count: U,
            },
            SampleValue::FileIoWriteTime {
                nanoseconds: T,
                count: U,
            },
            SampleValue::FileIoReadSize { bytes: T, count: U },
            SampleValue::FileIoWriteSize { bytes: T, count: U },
            SampleValue::SocketReadTime {
                nanoseconds: T,
                count: U,
            },
            SampleValue::SocketWriteTime {
                nanoseconds: T,
                count: U,
            },
            SampleValue::SocketReadSize { bytes: T, count: U },
            SampleValue::SocketWriteSize { bytes: T, count: U },
        ];

        for src in cases {
            let dst = RestructuredSample::from(&src);
            assert_eq!(dst.discriminant, src.discriminant() as u8);
            assert_eq!(dst.value.0, T);

            if src.sample_types().len() > 1 {
                let u = unsafe { dst.value.1.assume_init() };
                assert_eq!(
                    u, U,
                    "Matching {src:?}'s aos form failed: expected {U}, saw {u:?}"
                );
            }
        }
    }
}
