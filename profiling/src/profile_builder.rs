use crate::profiling::SymbolTable;
use datadog_profiling::profile::v2;
use indexmap::IndexMap;
use std::sync::Arc;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

#[derive(Debug, Clone, Copy)]
pub struct WallTime {
    pub instant: Instant,
    pub systemtime: SystemTime,
    #[allow(dead_code)]
    marker: (), // prevent direct instantiation out of this module
}

impl WallTime {
    pub fn now() -> Self {
        Self {
            instant: Instant::now(),
            systemtime: SystemTime::now(),
            marker: (),
        }
    }
}

pub struct ProfileBuilder {
    start_time: WallTime,
    locations: v2::ProfileSet<v2::Location>,
    mappings: v2::ProfileSet<v2::Mapping>,
    period_type: Option<v2::ValueType>,
    period: i64,
    sample_types: Vec<v2::ValueType>,
    samples: IndexMap<v2::Sample, Vec<i64>>,
    string_table: Arc<v2::LockedStringTable>,
    symbol_table: Arc<SymbolTable>,
}

impl ProfileBuilder {
    pub fn new(
        start_time: WallTime,
        sample_types: Vec<v2::ValueType>,
        period_type: Option<v2::ValueType>,
        period: i64,
        string_table: Arc<v2::LockedStringTable>,
        symbol_table: Arc<SymbolTable>,
    ) -> Self {
        Self {
            start_time,
            samples: Default::default(),
            locations: Default::default(),
            mappings: Default::default(),
            period_type,
            period,
            string_table,
            symbol_table,
            sample_types,
        }
    }

    pub fn add_location(&mut self, location: v2::Location) -> u64 {
        self.locations.add(location)
    }

    pub fn add_mapping(&mut self, mapping: v2::Mapping) -> u64 {
        self.mappings.add(mapping)
    }

    pub fn add_sample(&mut self, sample: v2::Sample, values: Vec<i64>) {
        if let Some(v) = self.samples.get_mut(&sample) {
            for (aggr, new) in v.iter_mut().zip(values.iter()) {
                *aggr += new;
            }
        } else {
            self.samples.insert(sample, values);
        }
    }

    pub fn end(self, stop_time: WallTime) -> anyhow::Result<ProfileIR> {
        let samples = self
            .samples
            .into_iter()
            .map(|(sample, values)| v2::pprof::Sample {
                location_ids: sample.location_ids,
                values,
                labels: sample.labels,
            })
            .collect();

        let time = self
            .start_time
            .systemtime
            .duration_since(UNIX_EPOCH)
            .unwrap_or(Duration::ZERO);
        let time_nanos = time.as_nanos().try_into().unwrap_or(i64::MAX);

        let duration = stop_time.instant.duration_since(self.start_time.instant);
        let duration_nanos = duration.as_nanos().try_into().unwrap_or(i64::MAX);

        let profile = v2::pprof::Profile {
            sample_types: self.sample_types,
            samples,
            mappings: self.mappings.export(),
            locations: self.locations.export(),
            functions: self.symbol_table.export(),
            string_table: self.string_table.strings(),
            drop_frames: 0,
            keep_frames: 0,
            time_nanos,
            duration_nanos,
            period_type: self.period_type,
            period: self.period,
            comment: vec![],
            default_sample_type: 0,
        };

        Ok(ProfileIR {
            start_time: self.start_time.systemtime,
            end_time: stop_time.systemtime,
            profile,
        })
    }
}

pub struct ProfileIR {
    pub start_time: SystemTime,
    pub end_time: SystemTime,
    pub profile: v2::pprof::Profile,
}
