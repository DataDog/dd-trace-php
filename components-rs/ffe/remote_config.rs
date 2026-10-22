// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use datadog_ffe::rules_based::{Configuration, UniversalFlagConfig};

/// Stage a complete RC update batch before changing the evaluator's configuration.
/// RC replaces a path with Remove followed by Add. Publishing the Remove immediately
/// would expose an empty configuration to concurrent CDN evaluations.
#[derive(Default)]
pub(crate) struct ConfigurationUpdate {
    pending: Option<Option<Configuration>>,
}

impl ConfigurationUpdate {
    pub(crate) fn add(&mut self, flags: &UniversalFlagConfig) -> anyhow::Result<()> {
        // If preparing the replacement fails, retain the installed configuration.
        self.pending = None;
        let flags = UniversalFlagConfig::from_json(flags.to_json().to_vec())?;
        self.pending = Some(Some(Configuration::from_server_response(flags)));
        Ok(())
    }

    pub(crate) fn remove(&mut self) {
        self.pending = Some(None);
    }

    /// Apply only after the manager has returned None. Dropping an unfinished batch
    /// leaves the installed configuration untouched.
    pub(crate) fn apply(self) {
        match self.pending {
            Some(Some(config)) => {
                super::store_config(config);
            }
            Some(None) => {
                super::clear_config();
            }
            None => {}
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::ffe::{self, DeliveryState};
    use datadog_ffe::rules_based::{
        get_assignment, AssignmentValue, EvaluationContext, EvaluationError, ExpectedFlagType,
    };
    use std::collections::HashMap;
    use std::sync::Arc;

    struct SharedConfiguration;

    impl SharedConfiguration {
        fn new() -> Self {
            ffe::use_agentless_config(true);
            ffe::clear_config();
            Self
        }
    }

    impl Drop for SharedConfiguration {
        fn drop(&mut self) {
            ffe::clear_config();
            ffe::use_agentless_config(false);
        }
    }

    fn flags(value: &str) -> UniversalFlagConfig {
        UniversalFlagConfig::from_json(
            serde_json::to_vec(&serde_json::json!({
                "createdAt": "2026-10-21T00:00:00Z", "environment": {"name": "test"},
                "flags": {"flag": {
                    "key": "flag", "enabled": true, "variationType": "STRING",
                    "variations": {"v": {"key": "v", "value": value}},
                    "allocations": [{"key": "static", "splits": [{"variationKey": "v", "shards": []}]}]
                }}
            }))
            .unwrap(),
        )
        .unwrap()
    }

    fn evaluate_on_another_thread() -> Result<String, EvaluationError> {
        std::thread::spawn(|| {
            let configuration = ffe::current_config();
            get_assignment(
                configuration.as_deref(),
                "flag",
                &EvaluationContext::new(None, Arc::new(HashMap::new())),
                ExpectedFlagType::String,
                chrono::Utc::now(),
            )
            .map(|assignment| match assignment.value {
                AssignmentValue::String(value) => value.as_str().to_owned(),
                other => panic!("unexpected assignment {other:?}"),
            })
        })
        .join()
        .unwrap()
    }

    fn install(value: &str) {
        let mut update = ConfigurationUpdate::default();
        update.add(&flags(value)).unwrap();
        update.apply();
    }

    #[test]
    fn cdn_replacement_keeps_evaluations_available_until_atomic_publication() {
        let _lock = ffe::tests::FFE_TEST_LOCK.lock().unwrap();
        let _configuration = SharedConfiguration::new();
        install("old");
        let version = ffe::ddog_ffe_config_version();

        let mut update = ConfigurationUpdate::default();
        update.remove();
        assert_eq!(evaluate_on_another_thread().unwrap(), "old");
        update.add(&flags("new")).unwrap();
        assert_eq!(evaluate_on_another_thread().unwrap(), "old");
        assert_eq!(ffe::ddog_ffe_config_version(), version);
        update.apply();
        assert_eq!(evaluate_on_another_thread().unwrap(), "new");
        assert_eq!(ffe::ddog_ffe_config_version(), version + 1);
        assert_eq!(ffe::delivery_state(), DeliveryState::Ready);
    }

    #[test]
    fn a_completed_removal_clears_configuration_but_an_abandoned_batch_does_not() {
        let _lock = ffe::tests::FFE_TEST_LOCK.lock().unwrap();
        let _configuration = SharedConfiguration::new();
        install("old");
        let mut abandoned = ConfigurationUpdate::default();
        abandoned.remove();
        drop(abandoned);
        assert_eq!(evaluate_on_another_thread().unwrap(), "old");

        let mut removal = ConfigurationUpdate::default();
        removal.remove();
        removal.apply();
        assert_eq!(
            evaluate_on_another_thread(),
            Err(EvaluationError::ConfigurationMissing)
        );
        assert_eq!(ffe::delivery_state(), DeliveryState::Stale);
    }
}
