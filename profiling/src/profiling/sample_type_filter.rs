use crate::config::SystemSettings;
use crate::profiling::{SampleValues, ValueType};

const MAX_SAMPLE_TYPES: usize = 15;

pub struct SampleTypeFilter {
    sample_types: Vec<ValueType>,
    sample_types_mask: [bool; MAX_SAMPLE_TYPES],
}

impl SampleTypeFilter {
    pub fn new(system_settings: &SystemSettings) -> Self {
        // Lay this out in the same order as SampleValues.
        static SAMPLE_TYPES: &[ValueType; MAX_SAMPLE_TYPES] = &[
            ValueType::new("sample", "count"),
            ValueType::new("wall-time", "nanoseconds"),
            ValueType::new("cpu-time", "nanoseconds"),
            ValueType::new("alloc-samples", "count"),
            ValueType::new("alloc-size", "bytes"),
            ValueType::new("timeline", "nanoseconds"),
            ValueType::new("exception-samples", "count"),
            ValueType::new("socket-read-time", "nanoseconds"),
            ValueType::new("socket-write-time", "nanoseconds"),
            ValueType::new("file-io-read-time", "nanoseconds"),
            ValueType::new("file-io-write-time", "nanoseconds"),
            ValueType::new("socket-read-size", "bytes"),
            ValueType::new("socket-write-size", "bytes"),
            ValueType::new("file-io-read-size", "bytes"),
            ValueType::new("file-io-write-size", "bytes"),
        ];

        let mut sample_types = Vec::with_capacity(SAMPLE_TYPES.len());
        let mut sample_types_mask = [false; MAX_SAMPLE_TYPES];

        if system_settings.profiling_enabled {
            // sample, wall-time, cpu-time
            let len = 2 + system_settings.profiling_experimental_cpu_time_enabled as usize;
            sample_types.extend_from_slice(&SAMPLE_TYPES[0..len]);
            sample_types_mask[0] = true;
            sample_types_mask[1] = true;
            sample_types_mask[2] = system_settings.profiling_experimental_cpu_time_enabled;

            // alloc-samples, alloc-size
            if system_settings.profiling_allocation_enabled {
                sample_types.extend_from_slice(&SAMPLE_TYPES[3..5]);
                sample_types_mask[3] = true;
                sample_types_mask[4] = true;
            }

            #[cfg(feature = "timeline")]
            if system_settings.profiling_timeline_enabled {
                sample_types.push(SAMPLE_TYPES[5]);
                sample_types_mask[5] = true;
            }

            #[cfg(feature = "exception_profiling")]
            if system_settings.profiling_exception_enabled {
                sample_types.push(SAMPLE_TYPES[6]);
                sample_types_mask[6] = true;
            }

            #[cfg(feature = "io_profiling")]
            if system_settings.profiling_io_enabled {
                sample_types.push(SAMPLE_TYPES[7]);
                sample_types_mask[7] = true;
                sample_types.push(SAMPLE_TYPES[8]);
                sample_types_mask[8] = true;
                sample_types.push(SAMPLE_TYPES[9]);
                sample_types_mask[9] = true;
                sample_types.push(SAMPLE_TYPES[10]);
                sample_types_mask[10] = true;
                sample_types.push(SAMPLE_TYPES[11]);
                sample_types_mask[11] = true;
                sample_types.push(SAMPLE_TYPES[12]);
                sample_types_mask[12] = true;
                sample_types.push(SAMPLE_TYPES[13]);
                sample_types_mask[13] = true;
                sample_types.push(SAMPLE_TYPES[14]);
                sample_types_mask[14] = true;
            }
        }

        Self {
            sample_types,
            sample_types_mask,
        }
    }

    pub fn sample_types(&self) -> Vec<ValueType> {
        self.sample_types.clone()
    }

    pub fn filter(&self, sample_values: SampleValues) -> Vec<i64> {
        let mut output = Vec::new();
        output.reserve_exact(self.sample_types.len());

        // Lay this out in the same order as SampleValues.
        // Allows us to slice the SampleValues as if they were an array.
        let values: [i64; MAX_SAMPLE_TYPES] = [
            sample_values.interrupt_count,
            sample_values.wall_time,
            sample_values.cpu_time,
            sample_values.alloc_samples,
            sample_values.alloc_size,
            sample_values.timeline,
            sample_values.exception,
            sample_values.socket_read_time,
            sample_values.socket_write_time,
            sample_values.file_read_time,
            sample_values.file_write_time,
            sample_values.socket_read_size,
            sample_values.socket_write_size,
            sample_values.file_read_size,
            sample_values.file_write_size,
        ];

        for (value, enabled) in values.into_iter().zip(self.sample_types_mask.iter()) {
            if *enabled {
                output.push(value);
            }
        }

        output
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
        let values = sample_type_filter.filter(get_samples());
        let types = sample_type_filter.sample_types();

        assert_eq!(types, Vec::<ValueType>::new());
        assert_eq!(values, Vec::<i64>::new());
    }

    #[test]
    fn filter_with_profiling_enabled() {
        let mut settings = get_system_settings();

        settings.profiling_enabled = true;
        settings.profiling_allocation_enabled = false;
        settings.profiling_experimental_cpu_time_enabled = false;

        let sample_type_filter = SampleTypeFilter::new(&settings);
        let values = sample_type_filter.filter(get_samples());
        let types = sample_type_filter.sample_types();

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
        let types = sample_type_filter.sample_types();

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
        let types = sample_type_filter.sample_types();

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
        let types = sample_type_filter.sample_types();

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
        let types = sample_type_filter.sample_types();

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
