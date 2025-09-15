use crate::config::SystemSettings;
use crate::inlinevec::InlineVec;
use crate::profiling::{SampleValues, ValueType};
use datadog_profiling::profiles::datatypes::MAX_SAMPLE_TYPES;
use std::collections::HashMap;
use std::hash::BuildHasherDefault;
use std::mem::Discriminant;

/// The sample values for a given profile type.
///
/// The repr(u8) is valid even though this holds data larger than u8; see the
/// documentation on primitive representations:
/// https://doc.rust-lang.org/reference/type-layout.html#primitive-representations
///
/// If the order of the enum is changed, or if variants are added or removed,
/// then other code below will need to be changed to match.
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

impl SampleValue {
    fn discriminant(&self) -> usize {
        // SAFETY: SampleValue uses a primitive representation.
        let r#repr = unsafe { *(self as *const Self as *const u8) };
        r#repr as usize
    }
}

pub type ProfileType = InlineVec<ValueType, MAX_SAMPLE_TYPES>;

/// This must have the same order that the SampleValue enum has these items.
const PROFILE_TYPES: [ProfileType; 13] = [
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

const SAMPLE_TYPE_WALL_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "wall-time",
        unit: "nanoseconds",
    },
    ValueType {
        r#type: "wall-time-sample", // called "sample" on legacy
        unit: "count",
    },
]);

const SAMPLE_TYPE_CPU_TIME: ProfileType = ProfileType::from([
    ValueType {
        r#type: "cpu-time",
        unit: "nanoseconds",
    },
]);

const SAMPLE_TYPE_ALLOC: ProfileType = ProfileType::from([
    ValueType {
        r#type: "alloc-size",
        unit: "bytes",
    },
    ValueType {
        r#type: "alloc-samples",
        unit: "count",
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
    pub fn sample_types(&self) -> ProfileType {
        let discriminant = core::mem::discriminant(self);
        PROFILE_TYPES[discriminant]
    }
}

pub struct SampleTypeFilter {
    enabled: [bool; PROFILE_TYPES.len()],
    enabled_types: Vec<ProfileType>,
}

impl SampleTypeFilter {
    pub fn new(config: &SystemSettings) -> Self {
        // This implementation is tied to the order SampleValue is defined.
        // We create an array of booleans that are element-wise associated to
        // the PROFILE_TYPES array.
        let mut enabled = [false; PROFILE_TYPES.len()];

        let wall_time = config.profiling_enabled;
        let cpu_time = config.profiling_experimental_cpu_time_enabled;
        let alloc = config.profiling_allocation_enabled;
        let timeline = config.profiling_timeline_enabled;
        let exception = config.profiling_exception_enabled;
        let io_profiling = cfg!(io_profiling) && config.profiling_io_enabled;

        let mask = [
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

        // Everything is disabled if profiling isn't disabled.
        if config.profiling_enabled {
            for (profile_type, is_enabled) in enabled.iter_mut().zip(mask) {
                *profile_type = is_enabled;
            }
        }

        let n_enabled = enabled.iter().map(|b| *b as usize).sum();
        let mut enabled_types = Vec::with_capacity(n_enabled);

        // Iterate element-wise over PROFILE_TYPES and enabled, and if
        // `enabled[i]` is true, then add `PROFILE_TYPES[i]`.
        enabled_types.extend(
            PROFILE_TYPES
                .iter()
                .zip(enabled)
                .filter(|(_, b)| *b)
                .map(|(p, _)| *p),
        );
        Self {
            enabled,
            enabled_types,
        }
    }

    pub fn enabled_types(&self) -> Vec<ProfileType> {
        self.enabled_types.clone()
    }

    pub fn filter(&self, mut sample_values: Vec<SampleValue>) -> Vec<SampleValue> {
        // This is a defensive programming measure. These _shouldn't_ ever
        // filter anything out--if so we've got a bug in our profiler.
        sample_values.retain(|sample_value| self.enabled[sample_value.discriminant()]);
        sample_values
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::profiling::tests::{get_samples, get_system_settings};

    #[test]
    fn filter_with_profiling_disabled() {
        let mut settings = get_system_settings();

        settings.profiling_enabled = false;
        settings.profiling_allocation_enabled = false;
        settings.profiling_experimental_cpu_time_enabled = false;

        let sample_type_filter = SampleTypeFilter::new(&settings);
        let sample_types = sample_type_filter.filter(get_samples()).next();
        assert_eq!(sample_types.next(), None);
        assert_eq!(sample_type_filter.enabled_types(), Vec::new());
    }

    #[test]
    fn filter_with_profiling_enabled() {
        let mut settings = get_system_settings();

        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = false;
        settings.profiling_experimental_cpu_time_enabled = false;

        let sample_type_filter = SampleTypeFilter::new(&settings);
        let mut values = sample_type_filter.filter(get_samples()).collect::<Vec<_>>();
        let types = sample_type_filter.enabled_types();

        values.sort_by(|a, b| a.discriminant().cmp(&b.discriminant()));
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
    fn filter_with_cpu_time() {
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = false;
        settings.profiling_experimental_cpu_time_enabled = true;

        let sample_type_filter = SampleTypeFilter::new(&settings);
        let values = sample_type_filter.filter(get_samples());
        let types = sample_type_filter.enabled_types();

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

        let sample_type_filter = SampleTypeFilter::new(&settings);
        let values = sample_type_filter.filter(get_samples());
        let types = sample_type_filter.enabled_types();

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
    fn filter_with_allocations_and_cpu_time() {
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = true;
        settings.profiling_experimental_cpu_time_enabled = true;

        let sample_type_filter = SampleTypeFilter::new(&settings);
        let values = sample_type_filter.filter(get_samples());
        let types = sample_type_filter.enabled_types();

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
    #[cfg(feature = "exception_profiling")]
    fn filter_with_cpu_time_and_exceptions() {
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_experimental_cpu_time_enabled = true;
        settings.profiling_exception_enabled = true;

        let sample_type_filter = SampleTypeFilter::new(&settings);
        let values = sample_type_filter.filter(get_samples());
        let types = sample_type_filter.enabled_types();

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
