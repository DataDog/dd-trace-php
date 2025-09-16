// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0 OR BSD-3-Clause

use crate::bitset::BitSet;
use crate::config::SystemSettings;
use crate::inlinevec::InlineVec;
use crate::profiling::ValueType;
use std::hash::{Hash, Hasher};

pub const MAX_SAMPLE_TYPES_PER_PROFILE: usize = 2;
pub const MAX_SAMPLE_VALUES: usize = 23;
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

/// Historically, this was ordered this way:
///  1. Always enabled types.
///  2. On by default types.
///  3. Off by default types.
/// But this doesn't really matter anymore, because the number of sample types
/// has grown.
#[derive(Default, Debug)]
#[repr(C)]
pub struct SampleValues {
    pub interrupt_count: i64,
    pub wall_time: i64,
    pub cpu_time: i64,
    pub alloc_samples: i64,
    pub alloc_size: i64,
    pub timeline: i64,
    pub exception: i64,
    pub socket_read_time: i64,
    pub socket_read_time_samples: i64,
    pub socket_write_time: i64,
    pub socket_write_time_samples: i64,
    pub file_read_time: i64,
    pub file_read_time_samples: i64,
    pub file_write_time: i64,
    pub file_write_time_samples: i64,
    pub socket_read_size: i64,
    pub socket_read_size_samples: i64,
    pub socket_write_size: i64,
    pub socket_write_size_samples: i64,
    pub file_read_size: i64,
    pub file_read_size_samples: i64,
    pub file_write_size: i64,
    pub file_write_size_samples: i64,
}

/// The sample values for a given profile type.
///
/// The repr(u8) is valid even though this holds data larger than u8; see the
/// documentation on primitive representations:
/// https://doc.rust-lang.org/reference/type-layout.html#primitive-representations
///
/// If the order of the enum is changed, or if variants are added or removed,
/// then [`PROFILE_TYPES`] needs to be changed.
#[repr(u8)]
pub enum SampleValue {
    WallTime { nanoseconds: i64, count: i64 },
    CpuTime { nanoseconds: i64 },
    Alloc { bytes: i64, count: i64 },
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

impl SampleValues {
    pub fn as_array(&self) -> [&i64; MAX_SAMPLE_VALUES] {
        // Use the same order as the members.
        [
            &self.interrupt_count,
            &self.wall_time,
            &self.cpu_time,
            &self.alloc_samples,
            &self.alloc_size,
            &self.timeline,
            &self.exception,
            &self.socket_read_time,
            &self.socket_read_time_samples,
            &self.socket_write_time,
            &self.socket_write_time_samples,
            &self.file_read_time,
            &self.file_read_time_samples,
            &self.file_write_time,
            &self.file_write_time_samples,
            &self.socket_read_size,
            &self.socket_read_size_samples,
            &self.socket_write_size,
            &self.socket_write_size_samples,
            &self.file_read_size,
            &self.file_read_size_samples,
            &self.file_write_size,
            &self.file_write_size_samples,
        ]
    }
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

    pub fn filter<'a>(
        &self,
        sample_values: &'a SampleValues,
    ) -> SampleIter<i64, impl Iterator<Item = i64> + 'a> {
        let bitset = self.samples.clone();
        let len = bitset.len();
        let iter =
            sample_values
                .as_array()
                .into_iter()
                .enumerate()
                .filter_map(move |(index, value)| {
                    if bitset.contains(index) {
                        Some(*value)
                    } else {
                        None
                    }
                });
        SampleIter { len, iter }
    }

    pub fn enabled_profile_types<'a>(
        &'a self,
    ) -> SampleIter<ProfileType, impl Iterator<Item = ProfileType> + 'a> {
        let len = self.num_enabled_profiles();
        let iter = PROFILE_TYPES
            .iter()
            .enumerate()
            .filter_map(|(offset, profile_type)| {
                self.profiles.contains(offset).then_some(*profile_type)
            });
        SampleIter { len, iter }
    }

    pub fn enabled_sample_types<'a>(
        &'a self,
    ) -> SampleIter<ValueType, impl Iterator<Item = ValueType> + 'a> {
        let len = self.num_enabled_sample_types();
        let iter = self.enabled_profile_types().flatten();
        SampleIter { len, iter }
    }
}

const SAMPLE_TYPE_WALL_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "sample", // todo: rename "wall-time-sample"
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
    fn discriminant(&self) -> usize {
        // SAFETY: SampleValue uses a primitive representation.
        let r#repr = unsafe { *(self as *const Self as *const u8) };
        r#repr as usize
    }
}

impl SampleValue {
    pub fn sample_types(&self) -> ProfileType {
        let discriminant = self.discriminant();
        PROFILE_TYPES[discriminant]
    }
}

pub struct SampleIter<T, I: Iterator<Item = T>> {
    len: usize,
    iter: I,
}

impl<T, I: Iterator<Item = T>> Iterator for SampleIter<T, I> {
    type Item = T;
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

impl<T, I: Iterator<Item = T>> ExactSizeIterator for SampleIter<T, I> {
    fn len(&self) -> usize {
        self.len
    }
}

#[cfg(test)]
mod test {
    use super::*;
    use crate::profiling::tests::{get_samples, get_system_settings};

    #[test]
    fn with_profiling_disabled() {
        let mut settings = get_system_settings();

        settings.profiling_enabled = false;
        settings.profiling_allocation_enabled = false;
        settings.profiling_experimental_cpu_time_enabled = false;

        let enabled_profiles = EnabledProfiles::new(&settings);
        let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
        let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();

        assert_eq!(types, Vec::<ValueType>::new());
        assert_eq!(values, Vec::<i64>::new());
    }

    #[test]
    fn with_profiling_enabled() {
        let mut settings = get_system_settings();

        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = false;
        settings.profiling_experimental_cpu_time_enabled = false;

        let enabled_profiles = EnabledProfiles::new(&settings);
        let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
        let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();

        assert_eq!(
            types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
            ]
        );
        assert_eq!(values, vec![10, 20]);
    }

    #[test]
    fn with_cpu_time() {
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = false;
        settings.profiling_experimental_cpu_time_enabled = true;

        let enabled_profiles = EnabledProfiles::new(&settings);
        let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
        let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();

        assert_eq!(
            types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("cpu-time", "nanoseconds"),
            ]
        );
        assert_eq!(values, vec![10, 20, 30]);
    }

    #[test]
    fn filter_with_allocations() {
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = true;
        settings.profiling_experimental_cpu_time_enabled = false;

        let enabled_profiles = EnabledProfiles::new(&settings);
        let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
        let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();

        assert_eq!(
            types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("alloc-samples", "count"),
                ValueType::new("alloc-size", "bytes"),
            ]
        );
        assert_eq!(values, vec![10, 20, 40, 50]);
    }

    #[test]
    fn with_allocations_and_cpu_time() {
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = true;
        settings.profiling_experimental_cpu_time_enabled = true;

        let enabled_profiles = EnabledProfiles::new(&settings);
        let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
        let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();

        assert_eq!(
            types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("cpu-time", "nanoseconds"),
                ValueType::new("alloc-samples", "count"),
                ValueType::new("alloc-size", "bytes"),
            ]
        );
        assert_eq!(values, vec![10, 20, 30, 40, 50]);
    }

    #[test]
    fn with_cpu_time_and_exceptions() {
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_experimental_cpu_time_enabled = true;
        settings.profiling_exception_enabled = true;

        let enabled_profiles = EnabledProfiles::new(&settings);
        let values = enabled_profiles.filter(&get_samples()).collect::<Vec<_>>();
        let types = enabled_profiles.enabled_sample_types().collect::<Vec<_>>();

        assert_eq!(
            types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("cpu-time", "nanoseconds"),
                ValueType::new("exception-samples", "count"),
            ]
        );
        assert_eq!(values, vec![10, 20, 30, 70]);
    }
}
