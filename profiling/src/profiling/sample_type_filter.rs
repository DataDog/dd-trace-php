use crate::config::SystemSettings;
use crate::profiling::{SampleValues, ValueType};

const MAX_SAMPLE_TYPES: usize = 7;

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
            if system_settings.profiling_experimental_timeline_enabled {
                sample_types.push(SAMPLE_TYPES[5]);
                sample_types_mask[5] = true;
            }

            #[cfg(feature = "exception_profiling")]
            if system_settings.profiling_exception_enabled {
                sample_types.push(SAMPLE_TYPES[6]);
                sample_types_mask[6] = true;
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
        ];

        for (value, enabled) in values.into_iter().zip(self.sample_types_mask.iter()) {
            if *enabled {
                output.push(value);
            }
        }

        output
    }
}
