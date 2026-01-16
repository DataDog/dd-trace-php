use anyhow::Context;
use arc_swap::ArcSwap;
use std::{
    collections::{HashMap, HashSet},
    hash::Hash,
    path::PathBuf,
    sync::{Arc, Mutex, Weak},
};

use crate::{
    client::{
        log::{debug, error, info},
        protocol,
    },
    rc,
    service::config_manager::AsmFeatureConfigManager,
    telemetry::{
        self, SpanMetricsGenerator, SpanMetricsSubmitter, TelemetryLogSubmitter,
        TelemetryLogsCollector, TelemetryLogsGenerator, TelemetryMetricSubmitter,
        TelemetryMetricsCollector, TelemetryTags, WAF_INIT, WAF_UPDATES,
    },
};

mod config_manager;
mod limiter;
mod sampler;
mod updateable_waf;
mod waf_diag;
mod waf_ruleset;

// --- Public API ---

pub struct ServiceManager {
    inner: Mutex<ServiceManagerInner>,
}

impl ServiceManager {
    pub fn new() -> Self {
        ServiceManager {
            inner: Mutex::new(ServiceManagerInner {
                services: HashMap::new(),
                last_service: None,
            }),
        }
    }

    pub fn get_service(
        &self,
        config: &ServiceFixedConfig,
        tel_collector: &mut impl TelemetryMetricSubmitter,
    ) -> anyhow::Result<Arc<Service>> {
        let mut inner = self.inner.lock().unwrap();

        if let Some(weak) = inner.services.get(config) {
            if let Some(service) = weak.upgrade() {
                inner.last_service = Some(service.clone());
                return Ok(service);
            }
        }

        let service = Arc::new(Service::new(config.clone(), tel_collector)?);
        inner
            .services
            .insert(config.clone(), Arc::downgrade(&service));
        inner.last_service = Some(service.clone());
        inner.cleanup();

        Ok(service)
    }

    pub fn notify_of_rc_updates(&self, shmem_path: &std::path::Path) {
        let inner = self.inner.lock().unwrap();

        for (config, weak) in &inner.services {
            if config.rem_cfg_settings.shmem_path != shmem_path {
                continue;
            }

            if let Some(service) = weak.upgrade() {
                drop(inner);
                if let Err(e) = service.poll_and_apply_rc() {
                    error!("Failed to apply RC update for {:?}: {}", shmem_path, e);
                }
                return;
            }
        }

        debug!(
            "No active service found for RC update path {:?}",
            shmem_path
        );
    }

    #[cfg(test)]
    fn service_count(&self) -> usize {
        let inner = self.inner.lock().unwrap();
        inner
            .services
            .values()
            .filter(|w| w.strong_count() > 0)
            .count()
    }
}

pub struct Service {
    fixed_config: ServiceFixedConfig,
    limiter: limiter::Limiter,
    schema_sampler: Option<sampler::SchemaSampler>, // empty => always sample
    // Serializes RC polling + config application. The poller lives here because
    // polling and applying must be atomic (can't have two threads poll, then both apply).
    rc_update_lock: Mutex<RcUpdateState>,

    // ideally, these two would be updated together atomically
    waf: updateable_waf::UpdateableWafInstance,
    config_snapshot: ArcSwap<ConfigSnapshot>, // config other than waf

    // Sometimes we generate logs before we even have service/env
    // Those need to be collected and submitted later
    logs_collector: TelemetryLogsCollector,
}

/// Legacy span metrics/meta from WAF initialization diagnostics.
#[derive(Debug, Clone, Default)]
pub struct InitDiagnosticsLegacy {
    pub rules_loaded: u32,
    pub rules_failed: u32,
    pub rules_errors: String,
}
impl SpanMetricsGenerator for InitDiagnosticsLegacy {
    fn generate_span_metrics(&'_ self, submitter: &mut dyn SpanMetricsSubmitter) {
        submitter.submit_metric(telemetry::EVENT_RULES_LOADED, self.rules_loaded as f64);
        submitter.submit_metric(telemetry::EVENT_RULES_FAILED, self.rules_failed as f64);
        submitter.submit_meta(telemetry::EVENT_RULES_ERRORS, self.rules_errors.clone());
    }
}

impl Service {
    pub fn fixed_config(&self) -> &ServiceFixedConfig {
        &self.fixed_config
    }

    pub fn telemetry_settings(&self) -> &protocol::TelemetrySettings {
        &self.fixed_config.telemetry_settings
    }

    pub fn configured_waf_timeout(&self) -> Option<std::time::Duration> {
        self.fixed_config
            .waf_settings
            .waf_timeout_us
            .map(std::time::Duration::from_micros)
    }

    pub fn new_context(&self) -> libddwaf::Context {
        self.waf.current().new_context()
    }

    pub fn config_snapshot(&self) -> arc_swap::Guard<Arc<ConfigSnapshot>> {
        self.config_snapshot.load()
    }

    pub fn should_force_keep(&self) -> bool {
        self.limiter.go_through()
    }

    pub fn waf_version() -> &'static str {
        libddwaf::version().to_str().unwrap_or("unknown")
    }

    pub fn should_extract_schema(&self, api_sec_samp_key: u64) -> bool {
        if !self.fixed_config.waf_settings.schema_extraction.enabled {
            return false;
        }

        if api_sec_samp_key == 0 {
            return false;
        }

        self.schema_sampler
            .as_ref()
            .is_none_or(|sampler| sampler.should_sample(api_sec_samp_key))
    }

    pub fn poll_and_apply_rc(&self) -> anyhow::Result<()> {
        let mut state = self.rc_update_lock.lock().unwrap();

        let cfg_dir = match state.poller {
            Some(ref mut poller) => match poller.poll()? {
                Some(cfg_dir) => cfg_dir,
                None => {
                    debug!("No new RC configuration");
                    return Ok(());
                }
            },
            None => {
                debug!("RC disabled for this service");
                return Ok(());
            }
        };

        self.apply_config(&mut state, cfg_dir)
    }

    pub fn take_pending_telemetry(&self) -> TelemetryMetricsCollector {
        let mut state = self.rc_update_lock.lock().unwrap();
        std::mem::take(&mut state.pending_telemetry_metrics)
    }

    pub fn take_pending_init_diagnostics_legacy(&self) -> Option<InitDiagnosticsLegacy> {
        let mut state = self.rc_update_lock.lock().unwrap();
        std::mem::take(&mut state.pending_init_diagnostics_legacy)
    }

    fn new(
        fixed_config: ServiceFixedConfig,
        tel_submitter: &mut impl TelemetryMetricSubmitter,
    ) -> anyhow::Result<Self> {
        let waf_settings = &fixed_config.waf_settings;
        let rc_settings = &fixed_config.rem_cfg_settings;

        let mut tags = TelemetryTags::new();
        tags.add("waf_version", Self::waf_version());

        // Load WAF ruleset
        let maybe_ruleset = if let Some(path) = &waf_settings.rules_file {
            waf_ruleset::WafRuleset::from_file(PathBuf::from(path))
                .with_context(|| format!("Error loading WAF ruleset from file {:?}", path))
        } else {
            waf_ruleset::WafRuleset::from_default_file()
                .with_context(|| "Error loading WAF ruleset from default file")
        };

        let ruleset = match maybe_ruleset {
            Ok(ruleset) => ruleset,
            Err(e) => {
                tags.add("success", "false");
                anyhow::bail!(e);
            }
        };

        // Create WAF instance
        let obfuscator = libddwaf::Obfuscator::new(
            waf_settings.obfuscator_key_regex.as_deref(),
            waf_settings.obfuscator_value_regex.as_deref(),
        );
        let config = libddwaf::Config::new(obfuscator);
        let mut diagnostics =
            libddwaf::object::WafOwnedDefaultAllocator::<libddwaf::object::WafMap>::default();
        let maybe_uwafi = updateable_waf::UpdateableWafInstance::new(
            ruleset.into(),
            Some(&config),
            Some(&mut diagnostics),
        )
        .with_context(|| "Error creating UpdateableWafInstance");

        // Extract rules version from diagnostics
        let rules_version = waf_diag::extract_ruleset_version(&diagnostics);
        if let Some(ref v) = rules_version {
            tags.add("event_rules_version", v);
        } else {
            tags.add("event_rules_version", "unknown");
        }

        // Extract legacy init diagnostics
        let init_diagnostics_legacy = waf_diag::extract_init_diagnostics_legacy(&diagnostics);

        // Submit waf.init metric
        tags.add("success", maybe_uwafi.is_ok().to_string());
        tel_submitter.submit_metric(WAF_INIT, 1.0, tags);
        let uwafi = maybe_uwafi?;

        // Initialization of remaining components
        let limiter = limiter::Limiter::new(waf_settings.trace_rate_limit);
        let poller = if rc_settings.enabled {
            Some(rc::ConfigPoller::new(&rc_settings.shmem_path))
        } else {
            None
        };

        let schema_sampler = if waf_settings.schema_extraction.enabled
            && waf_settings.schema_extraction.sampling_period >= 1.0
        {
            Some(sampler::SchemaSampler::new(
                waf_settings.schema_extraction.sampling_period as u32,
            ))
        } else {
            None
        };

        let asm_always_enabled = fixed_config.always_enabled;

        let service = Service {
            fixed_config,
            waf: uwafi,
            limiter,
            schema_sampler,
            rc_update_lock: Mutex::new(RcUpdateState {
                poller,
                last_configs: HashSet::new(),
                asm_feature_config_manager: AsmFeatureConfigManager::new(),
                pending_telemetry_metrics: TelemetryMetricsCollector::new(),
                pending_init_diagnostics_legacy: Some(init_diagnostics_legacy),
            }),
            config_snapshot: ArcSwap::from_pointee(ConfigSnapshot::new(
                asm_always_enabled,
                rules_version,
            )),
            logs_collector: TelemetryLogsCollector::new(),
        };
        service.poll_and_apply_rc()?;
        Ok(service)
    }

    fn apply_config(
        &self,
        state: &mut RcUpdateState,
        cfg_dir: rc::ConfigDirectory,
    ) -> anyhow::Result<()> {
        debug!("Applying config for runtime id {}", cfg_dir.runtime_id()?);

        let mut new_snapshot = (**self.config_snapshot.load()).clone();
        let mut new_configs = HashSet::new();
        let mut waf_changed = false;
        let mut all_diagnostics = Vec::new();
        let mut rules_version: Option<String> = None;

        for maybe_cfg in cfg_dir.iter()? {
            let cfg = maybe_cfg?;
            let rc_path = cfg.rc_path();
            new_configs.insert(rc_path.to_string());

            let product = cfg.product();
            debug!(
                "Processing config: rc_path={}, product={}",
                rc_path,
                product.name()
            );
            match product.name() {
                "ASM_FEATURES" => {
                    let shmem = cfg.read()?;
                    let data = unsafe { shmem.as_slice() };
                    state
                        .asm_feature_config_manager
                        .add(rc_path.to_string(), data)?;
                }
                "ASM_DD" | "ASM" | "ASM_DATA" => {
                    let shmem = cfg.read()?;
                    let data = unsafe { shmem.as_slice() };

                    let ruleset = match waf_ruleset::WafRuleset::from_slice(data) {
                        Ok(ruleset) => ruleset,
                        Err(e) => {
                            error!("Failed to parse WAF config {}: {}", rc_path, e);
                            continue;
                        }
                    };

                    let waf_obj: libddwaf::object::WafObject = ruleset.into();
                    let mut diagnostics = Default::default();

                    let result =
                        self.waf
                            .add_or_update_config(rc_path, &waf_obj, Some(&mut diagnostics));

                    if result {
                        debug!("Added/updated WAF config: {}", rc_path);
                        if product.name() == "ASM_DD" {
                            rules_version = waf_diag::extract_ruleset_version(&diagnostics);
                            new_snapshot =
                                new_snapshot.with_new_rules_version(rules_version.clone());
                        }
                        waf_changed = true;
                    } else {
                        error!("Failed to add WAF config: {}", rc_path);
                    }

                    all_diagnostics.push((rc_path.to_string(), diagnostics));
                }
                _ => {
                    debug!("Ignoring unknown product: {:?}", product);
                }
            }
        }

        for old_path in state.last_configs.difference(&new_configs) {
            if old_path.contains("/ASM_FEATURES/") {
                state.asm_feature_config_manager.remove(old_path);
            } else if self.waf.remove_config(old_path) {
                debug!("Removed WAF config: {}", old_path);
                waf_changed = true;
            }
        }
        state.last_configs = new_configs;

        // Telemetry waf.config_errors metrics and telemetry logs - always process
        // Use new rules_version if set, otherwise fall back to existing snapshot's version
        let version = rules_version
            .as_deref()
            .or(new_snapshot.rules_version.as_deref())
            .unwrap_or("unknown");
        for (rc_path, diagnostics) in &all_diagnostics {
            waf_diag::report_diagnostics_errors(
                rc_path,
                diagnostics,
                version,
                &mut state.pending_telemetry_metrics,
                &self.logs_collector,
            );
        }

        if waf_changed {
            let update_success = match self.waf.update() {
                Ok(_) => true,
                Err(e) => {
                    error!("Failed to rebuild WAF after config update: {}", e);
                    false
                }
            };

            // Telemetry waf.updates
            let mut tags = TelemetryTags::new();
            tags.add("waf_version", Service::waf_version())
                .add("event_rules_version", version)
                .add("success", update_success.to_string());
            state
                .pending_telemetry_metrics
                .submit_metric(WAF_UPDATES, 1.0, tags);
        }

        let asm_features = state.asm_feature_config_manager.build_final();

        new_snapshot = new_snapshot.with_asm_features(
            self.fixed_config.always_enabled,
            asm_features.asm,
            asm_features.auto_user_instrum,
        );
        if self.fixed_config.always_enabled && !new_snapshot.asm_enabled {
            info!(
                "ASM_FEATURES requested that ASM be disabled, but it's \
                forced into the enabled state by configuration",
            );
        }

        info!("Updating config snapshot with {:?}", new_snapshot);
        self.config_snapshot.store(Arc::new(new_snapshot));

        Ok(())
    }
}
impl TelemetryLogsGenerator for Service {
    fn generate_telemetry_logs(&'_ self, submitter: &mut dyn TelemetryLogSubmitter) {
        self.logs_collector.generate_telemetry_logs(submitter);
    }
}

#[derive(Eq, PartialEq, Hash, Debug, Clone)]
pub struct ServiceFixedConfig {
    always_enabled: bool,
    waf_settings: protocol::WafSettings,
    rem_cfg_settings: protocol::RemoteConfigSettings,
    telemetry_settings: protocol::TelemetrySettings,
}

impl ServiceFixedConfig {
    pub fn new(
        always_enabled: bool,
        waf_settings: protocol::WafSettings,
        rem_cfg_settings: protocol::RemoteConfigSettings,
        telemetry_settings: protocol::TelemetrySettings,
    ) -> Self {
        ServiceFixedConfig {
            always_enabled,
            waf_settings,
            rem_cfg_settings,
            telemetry_settings,
        }
    }

    pub fn config_sync_settings(
        &self,
    ) -> (
        &protocol::RemoteConfigSettings,
        &protocol::TelemetrySettings,
    ) {
        (&self.rem_cfg_settings, &self.telemetry_settings)
    }

    pub fn new_from_config_sync(&self, args: protocol::ConfigSyncArgs) -> Option<Self> {
        let new_rem_cfg_path = PathBuf::from(args.rem_cfg_path);
        if new_rem_cfg_path != self.rem_cfg_settings.shmem_path
            || args.telemetry_settings != self.telemetry_settings
        {
            let mut new_cfg = self.clone();
            new_cfg.rem_cfg_settings = protocol::RemoteConfigSettings {
                enabled: !new_rem_cfg_path.as_os_str().is_empty(),
                shmem_path: new_rem_cfg_path,
            };
            new_cfg.telemetry_settings = args.telemetry_settings;
            Some(new_cfg)
        } else {
            None
        }
    }
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum AutoUserInstrumMode {
    #[default]
    Undefined,
    Unknown,
    Disabled,
    Identification,
    Anonymization,
}

impl AutoUserInstrumMode {
    pub fn as_str(&self) -> &'static str {
        match self {
            AutoUserInstrumMode::Undefined => "undefined",
            AutoUserInstrumMode::Unknown => "unknown",
            AutoUserInstrumMode::Disabled => "disabled",
            AutoUserInstrumMode::Identification => "identification",
            AutoUserInstrumMode::Anonymization => "anonymization",
        }
    }
}

#[derive(Debug, Default, Clone)]
pub struct ConfigSnapshot {
    pub asm_enabled: bool,
    pub auto_user_instrum: AutoUserInstrumMode,
    pub rules_version: Option<String>,
}
impl ConfigSnapshot {
    pub fn new(asm_always_enabled: bool, rules_version: Option<String>) -> Self {
        Self {
            asm_enabled: asm_always_enabled,
            auto_user_instrum: AutoUserInstrumMode::Undefined,
            rules_version,
        }
    }

    pub fn with_asm_features(
        &self,
        asm_always_enabled: bool,
        asm_enabled: bool,
        auto_user_instrum: AutoUserInstrumMode,
    ) -> Self {
        Self {
            asm_enabled: asm_always_enabled || asm_enabled,
            auto_user_instrum,
            rules_version: self.rules_version.clone(),
        }
    }

    pub fn with_new_rules_version(&self, rules_version: Option<String>) -> Self {
        Self {
            asm_enabled: self.asm_enabled,
            auto_user_instrum: self.auto_user_instrum,
            rules_version,
        }
    }
}

// --- Implementation details ---

struct ServiceManagerInner {
    services: HashMap<ServiceFixedConfig, Weak<Service>>,
    last_service: Option<Arc<Service>>,
}

impl ServiceManagerInner {
    fn cleanup(&mut self) {
        self.services.retain(|_, weak| weak.strong_count() > 0);
    }
}

struct RcUpdateState {
    poller: Option<rc::ConfigPoller>,
    last_configs: HashSet<String>,
    asm_feature_config_manager: AsmFeatureConfigManager,
    pending_telemetry_metrics: TelemetryMetricsCollector,
    pending_init_diagnostics_legacy: Option<InitDiagnosticsLegacy>,
}

// --- Tests ---

#[cfg(test)]
mod tests {
    use super::*;

    const TEST_RULES_FILE: &str = concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/src/service/testdata/minimal_rules.json"
    );

    struct NoopTelemetrySubmitter;

    impl TelemetryMetricSubmitter for NoopTelemetrySubmitter {
        fn submit_metric(
            &mut self,
            _key: crate::telemetry::MetricName,
            _value: f64,
            _tags: TelemetryTags,
        ) {
            // no-op
        }
    }

    fn make_config(id: u32) -> ServiceFixedConfig {
        ServiceFixedConfig::new(
            true,
            protocol::WafSettings {
                rules_file: Some(TEST_RULES_FILE.to_string()),
                waf_timeout_us: Some(10000),
                trace_rate_limit: 100,
                obfuscator_key_regex: None,
                obfuscator_value_regex: None,
                schema_extraction: protocol::SchemaExtraction {
                    enabled: false,
                    sampling_period: 1.0,
                },
            },
            protocol::RemoteConfigSettings {
                enabled: false,
                shmem_path: PathBuf::from(format!("/tmp/test_{}", id)),
            },
            protocol::TelemetrySettings {
                service_name: "test".to_string(),
                env_name: "test".to_string(),
            },
        )
    }

    #[test]
    fn service_manager_returns_same_service_for_same_config() {
        let manager = ServiceManager::new();
        let config = make_config(1);

        let s1 = manager
            .get_service(&config, &mut NoopTelemetrySubmitter)
            .unwrap();
        let s2 = manager
            .get_service(&config, &mut NoopTelemetrySubmitter)
            .unwrap();

        assert!(Arc::ptr_eq(&s1, &s2));
        assert_eq!(manager.service_count(), 1);
    }

    #[test]
    fn service_manager_returns_different_services_for_different_configs() {
        let manager = ServiceManager::new();
        let config1 = make_config(1);
        let config2 = make_config(2);

        let s1 = manager
            .get_service(&config1, &mut NoopTelemetrySubmitter)
            .unwrap();
        let s2 = manager
            .get_service(&config2, &mut NoopTelemetrySubmitter)
            .unwrap();

        assert!(!Arc::ptr_eq(&s1, &s2));
        assert_eq!(manager.service_count(), 2);
    }

    #[test]
    fn service_manager_cleans_up_expired_services() {
        let manager = ServiceManager::new();
        let config1 = make_config(1);
        let config2 = make_config(2);

        let s1 = manager
            .get_service(&config1, &mut NoopTelemetrySubmitter)
            .unwrap();
        let _s2 = manager
            .get_service(&config2, &mut NoopTelemetrySubmitter)
            .unwrap();
        assert_eq!(manager.service_count(), 2);

        drop(_s2);

        // Trigger cleanup by getting another service
        let _s3 = manager
            .get_service(&config1, &mut NoopTelemetrySubmitter)
            .unwrap();
        assert_eq!(manager.service_count(), 1);
        assert!(Arc::ptr_eq(&s1, &_s3));
    }

    #[test]
    fn service_manager_keeps_last_service_alive() {
        let manager = ServiceManager::new();
        let config1 = make_config(1);
        let config2 = make_config(2);

        let s1 = manager
            .get_service(&config1, &mut NoopTelemetrySubmitter)
            .unwrap();
        drop(s1);

        // s1's config should still be alive (last_service keeps it)
        let s1_again = manager
            .get_service(&config1, &mut NoopTelemetrySubmitter)
            .unwrap();
        assert_eq!(manager.service_count(), 1);

        // Now get a different service, making it the last_service
        let s2 = manager
            .get_service(&config2, &mut NoopTelemetrySubmitter)
            .unwrap();
        drop(s1_again);

        // Trigger cleanup - config1 should now be gone
        let _ = manager
            .get_service(&config2, &mut NoopTelemetrySubmitter)
            .unwrap();
        assert_eq!(manager.service_count(), 1);

        // Getting config1 should create a new service
        let s1_new = manager
            .get_service(&config1, &mut NoopTelemetrySubmitter)
            .unwrap();
        assert_eq!(manager.service_count(), 2);
        drop(s2);
        drop(s1_new);
    }
}
