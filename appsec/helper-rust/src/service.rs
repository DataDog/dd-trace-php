use anyhow::Context;
use arc_swap::ArcSwap;
use std::{
    collections::HashMap,
    hash::Hash,
    path::PathBuf,
    sync::{Arc, Mutex, Weak},
    time::{Duration, Instant},
};

use settings::{RemoteConfigSettings, TelemetrySettings, WafSettings};

use crate::{
    client::log::{debug, error, info, warning},
    rc,
    telemetry::{
        self, SpanMetricsGenerator, SpanMetricsSubmitter, TelemetryLog, TelemetryLogSubmitter,
        TelemetryLogsCollector, TelemetryLogsGenerator, TelemetryMetricSubmitter,
        TelemetryMetricsCollector, TelemetryMetricsGenerator, TelemetryTags, WAF_INIT, WAF_UPDATES,
    },
};

#[cfg(target_os = "linux")]
mod fd_diagnostics;
mod limiter;
mod metrics;
mod sampler;
pub mod settings;
mod updateable_waf;
mod waf_diag;
mod waf_ruleset;

// --- Public API ---

pub struct ServiceManager {
    inner: Mutex<ServiceManagerInner>,
    #[cfg(target_os = "linux")]
    fd_diagnostics: Mutex<fd_diagnostics::FdDiagnostics>,
}

impl ServiceManager {
    pub fn new() -> Self {
        ServiceManager {
            #[cfg(target_os = "linux")]
            fd_diagnostics: Mutex::new(fd_diagnostics::FdDiagnostics::new()),
            inner: Mutex::new(ServiceManagerInner {
                services: HashMap::new(),
                last_service: None,
                failures: HashMap::new(),
            }),
        }
    }

    pub fn describe_fd_exhaustion(&self, error: &anyhow::Error) -> String {
        #[cfg(target_os = "linux")]
        return self.fd_diagnostics.lock().unwrap().describe_error(error);
        #[cfg(not(target_os = "linux"))]
        {
            let _ = error;
            String::new()
        }
    }

    pub fn get_service(
        &self,
        config: &ServiceFixedConfig,
        tel_collector: &mut dyn TelemetryMetricSubmitter,
    ) -> anyhow::Result<Arc<Service>> {
        let mut inner = self.inner.lock().unwrap();

        if let Some(weak) = inner.services.get(config) {
            if let Some(service) = weak.upgrade() {
                inner.last_service = Some(service.clone());
                return Ok(service);
            }
        }

        // A service that failed to build (e.g. a broken rules file) is retried with backoff,
        // instead of on every request of every worker.
        let now = Instant::now();
        if let Some(failure) = inner.failures.get(config) {
            if now < failure.retry_at {
                anyhow::bail!(
                    "service failed to build {} time(s), next retry in {:?}: {}",
                    failure.attempts,
                    failure.retry_at - now,
                    failure.error
                );
            }
        }

        let service = match Service::new(config.clone(), tel_collector) {
            Ok(service) => Arc::new(service),
            Err(err) => {
                let attempts = inner.failures.get(config).map_or(0, |f| f.attempts) + 1;
                let backoff = SERVICE_RETRY_BASE
                    .saturating_mul(1 << attempts.min(16))
                    .min(SERVICE_RETRY_MAX);
                inner.failures.insert(
                    config.clone(),
                    ServiceFailure {
                        attempts,
                        retry_at: now + backoff,
                        error: format!("{err:#}"),
                    },
                );
                return Err(err);
            }
        };
        inner.failures.remove(config);
        inner
            .services
            .insert(config.clone(), Arc::downgrade(&service));
        inner.last_service = Some(service.clone());
        inner.cleanup();

        Ok(service)
    }

    pub fn notify_of_rc_updates(&self, shmem_path: &std::path::Path) {
        // Services differing only in WAF or telemetry settings share an RC
        // path, and each polls with its own reader.
        let services: Vec<Arc<Service>> = {
            let inner = self.inner.lock().unwrap();
            inner
                .services
                .iter()
                .filter(|(config, _)| config.rem_cfg_settings.shmem_path() == Some(shmem_path))
                .filter_map(|(_, weak)| weak.upgrade())
                .collect()
        };

        if services.is_empty() {
            debug!(
                "No active service found for RC update path {:?}",
                shmem_path
            );
        }

        for service in services {
            if let Err(e) = service.poll_and_apply_rc() {
                let fd_diagnostics = self.describe_fd_exhaustion(&e);
                error!(
                    "Failed to apply RC update for {:?} (service with config {:?}): {:#}{}",
                    shmem_path, service.fixed_config, e, fd_diagnostics
                );
            }
        }
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

    // Sometimes we generate logs before we even have service/env or the errors
    // happen during an RC update from a sidecar notification.
    // Those need to be collected and submitted later
    logs_collector: TelemetryLogsCollector,

    // Track concurrent worker count (clients handling requests)
    worker_count: metrics::WorkerCountState,

    // Counts the RC updates applied. Bound clients submit the telemetry they
    // generate without waiting for a request: the extension sends none while
    // AppSec is inactive.
    rc_updates: tokio::sync::watch::Sender<u64>,
}

/// Legacy span metrics/meta from WAF initialization diagnostics.
#[derive(Debug, Clone, Default)]
pub struct InitDiagnosticsLegacy {
    pub rules_loaded: u32,
    pub rules_failed: u32,
    pub rules_errors: String,
}
impl InitDiagnosticsLegacy {
    fn merge(&mut self, other: Self) {
        self.rules_loaded += other.rules_loaded;
        self.rules_failed += other.rules_failed;
        if other.rules_errors != "{}" {
            let mut base: serde_json::Map<String, serde_json::Value> =
                serde_json::from_str(&self.rules_errors).unwrap_or_default();
            if let Ok(other_map) =
                serde_json::from_str::<serde_json::Map<_, _>>(&other.rules_errors)
            {
                base.extend(other_map);
            }
            self.rules_errors = serde_json::to_string(&base).unwrap_or_else(|_| "{}".to_string());
        }
    }
}
impl SpanMetricsGenerator for InitDiagnosticsLegacy {
    fn generate_span_metrics(&'_ self, submitter: &mut dyn SpanMetricsSubmitter) {
        submitter.submit_metric(telemetry::EVENT_RULES_LOADED, self.rules_loaded as f64);
        submitter.submit_metric(telemetry::EVENT_RULES_FAILED, self.rules_failed as f64);
        submitter.submit_meta(telemetry::EVENT_RULES_ERRORS, self.rules_errors.clone());
    }
}

impl Service {
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

        let result = self.apply_config(&mut state, cfg_dir);
        drop(state);
        self.rc_updates.send_modify(|count| *count += 1);
        result
    }

    /// Changes whenever an RC update was applied, successfully or not.
    pub fn subscribe_rc_updates(&self) -> tokio::sync::watch::Receiver<u64> {
        self.rc_updates.subscribe()
    }

    fn take_pending_telemetry_metrics(&self) -> impl TelemetryMetricsGenerator {
        let mut state = self.rc_update_lock.lock().unwrap();
        let pending = std::mem::take(&mut state.pending_telemetry_metrics);
        pending.into_generator()
    }

    pub fn take_pending_init_diagnostics_legacy(&self) -> Option<InitDiagnosticsLegacy> {
        let mut state = self.rc_update_lock.lock().unwrap();
        std::mem::take(&mut state.pending_init_diagnostics_legacy)
    }

    pub fn increment_worker_count(&self) {
        self.worker_count.increment();
    }

    pub fn decrement_worker_count(&self) {
        self.worker_count.decrement();
    }

    fn new(
        fixed_config: ServiceFixedConfig,
        tel_submitter: &mut dyn TelemetryMetricSubmitter,
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
            Ok(waf_ruleset::WafRuleset::from_default())
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

        // Submit waf.config_errors for any errors in the bundled rules
        let logs_collector = TelemetryLogsCollector::new();
        waf_diag::report_diagnostics_errors(
            None,
            &diagnostics,
            rules_version.as_deref().unwrap_or("unknown"),
            "init",
            tel_submitter,
            &logs_collector,
        );

        // Initialization of remaining components
        let limiter = limiter::Limiter::new(waf_settings.trace_rate_limit);
        let poller = rc_settings.shmem_path().map(rc::ConfigPoller::new);

        let schema_sampler = if waf_settings.schema_extraction.enabled
            && waf_settings.schema_extraction.sampling_period >= 1.0
        {
            Some(sampler::SchemaSampler::new(
                waf_settings.schema_extraction.sampling_period as u32,
            ))
        } else {
            None
        };

        let service = Service {
            fixed_config,
            waf: uwafi,
            limiter,
            schema_sampler,
            rc_update_lock: Mutex::new(RcUpdateState {
                poller,
                last_configs: HashMap::new(),
                pending_telemetry_metrics: TelemetryMetricsCollector::default(),
                pending_init_diagnostics_legacy: Some(init_diagnostics_legacy),
            }),
            config_snapshot: ArcSwap::from_pointee(ConfigSnapshot::new(rules_version)),
            logs_collector,
            worker_count: Default::default(),
            rc_updates: tokio::sync::watch::Sender::new(0),
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

        // map rc path -> shm path
        // the shm path only changes if the contents of the config changes
        let new_configs = cfg_dir
            .iter()
            .map_err(|e| {
                let any_error = e.context("Failed to start iteration over configs");
                self.log_general_rc_error(&any_error);
                any_error
            })?
            .map(|res_cfg| {
                res_cfg
                    .map_err(|e| {
                        let any_error = e.context("Failure during config iteration");
                        self.log_general_rc_error(&any_error);
                        any_error
                    })
                    .map(|cfg: rc::Config<'_>| {
                        (cfg.rc_path().clone(), cfg.shm_path().to_path_buf())
                    })
            })
            .collect::<Result<HashMap<_, _>, anyhow::Error>>()?;

        let mut new_snapshot = (**self.config_snapshot.load()).clone();
        let mut waf_changed = false;
        let mut all_diagnostics = Vec::new();
        let mut rules_version: Option<String> = None;

        // Handle removal of configs
        for old_path in state
            .last_configs
            .keys()
            .filter(|k| !new_configs.contains_key(*k) && is_waf_product(k))
        {
            let res = self.waf.remove_config(old_path.as_str());
            match res {
                Ok(true) => {
                    debug!("Removed WAF config: {:?}", old_path);
                    waf_changed = true;
                }
                Ok(false) => {
                    warning!("No WAF config found to remove: {:?}", old_path);
                }
                Err(e) => {
                    self.log_general_rc_error(&e.context(format!(
                        concat!(
                            "Failed to remove WAF config {:?}; ",
                            "this should happen only when reading the default config"
                        ),
                        old_path
                    )));
                }
            }
        }

        // cfg_dir iteration worked above, so it will presumably work here too
        let cfg_iter = cfg_dir.iter()?;
        for maybe_cfg in cfg_iter {
            // Handle each config in the new config directory
            let cfg = maybe_cfg?;
            let rc_path = cfg.rc_path();

            let result = self.apply_config_file(
                &cfg,
                rc_path,
                cfg.shm_path(),
                state,
                &mut all_diagnostics,
                &mut rules_version,
                &mut new_snapshot,
                &mut waf_changed,
            );
            if let Err(e) = result {
                self.log_product_rc_error(rc_path, &self.logs_collector, e);
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
                Some(rc_path),
                diagnostics,
                version,
                "update",
                &mut state.pending_telemetry_metrics,
                &self.logs_collector,
            );
        }

        if waf_changed {
            // do WAF update
            let update_success = match self.waf.update() {
                Ok(_) => {
                    info!(
                        "WAF updated successfully for service with config {:?}. RC configs: {:?}",
                        self.fixed_config,
                        self.waf.config_paths(None)
                    );
                    true
                }
                Err(e) => {
                    self.log_general_rc_error(
                        &e.context("Failed to rebuild WAF after config update"),
                    );
                    false
                }
            };

            // Legacy span metrics/meta (e.g. _dd.appsec.event_rules.loaded)
            state.pending_init_diagnostics_legacy = Some(
                all_diagnostics
                    .iter()
                    .map(|(_, d)| waf_diag::extract_init_diagnostics_legacy(d))
                    .fold(InitDiagnosticsLegacy::default(), |mut acc, d| {
                        acc.merge(d);
                        acc
                    }),
            );

            // Telemetry waf.updates
            let mut tags = TelemetryTags::new();
            tags.add("waf_version", Service::waf_version())
                .add("event_rules_version", version)
                .add("success", update_success.to_string());
            state
                .pending_telemetry_metrics
                .submit_metric(WAF_UPDATES, 1.0, tags);
        }

        info!("Updating config snapshot with {:?}", new_snapshot);
        self.config_snapshot.store(Arc::new(new_snapshot));

        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    fn apply_config_file(
        &self,
        cfg: &rc::Config,
        rc_path: &rc::RcPath,
        shm_path: &std::path::Path,
        state: &mut RcUpdateState,
        all_diagnostics: &mut Vec<(
            rc::RcPath,
            libddwaf::object::WafOwnedDefaultAllocator<libddwaf::object::WafMap>,
        )>,
        rules_version: &mut Option<String>,
        new_snapshot: &mut ConfigSnapshot,
        waf_changed: &mut bool,
    ) -> anyhow::Result<()> {
        let product = cfg.product();
        if state.last_configs.get(rc_path).map(PathBuf::as_path) == Some(shm_path) {
            debug!("Config unchanged since previous update: {:?}", rc_path);
            return Ok(());
        }

        debug!(
            "Processing config: rc_path={:?}, product={}",
            rc_path,
            product.name()
        );
        match product.name() {
            "ASM_DD" | "ASM" | "ASM_DATA" => {
                let shmem = cfg.read()?;
                let data = unsafe { shmem.as_slice() };

                let ruleset = waf_ruleset::WafRuleset::from_slice(data)
                    .with_context(|| format!("Failed to parse WAF config for {:?}", rc_path))?;

                let waf_obj: libddwaf::object::WafObject = ruleset.into();
                let mut diagnostics = Default::default();

                let upd_result = self.waf.add_or_update_config(
                    rc_path.as_str(),
                    &waf_obj,
                    Some(&mut diagnostics),
                );

                if upd_result.is_ok() {
                    debug!("Added/updated WAF config: {:?}", rc_path);
                    if product.name() == "ASM_DD" {
                        *rules_version = waf_diag::extract_ruleset_version(&diagnostics);
                        *new_snapshot = new_snapshot.with_new_rules_version(rules_version.clone());
                    }
                    *waf_changed = true;
                }

                all_diagnostics.push((rc_path.clone(), diagnostics));
                upd_result
            }
            _ => {
                debug!("Ignoring unknown product: {:?}", product);
                Ok(())
            }
        }
    }

    fn log_product_rc_error(
        &self,
        rc_path: &rc::RcPath,
        logs_submitter: &TelemetryLogsCollector,
        error: anyhow::Error,
    ) {
        let message = format!(
            "Failed to apply config {:?} in service with config {:?}: {:#}",
            rc_path, self.fixed_config, error
        );

        warning!("{}", message);

        if let Some(parsed_key) = rc::ParsedConfigKey::from_rc_path(rc_path) {
            let identifier = format!("rc::{}::exception", parsed_key.product);
            let mut tags = TelemetryTags::new();
            tags.add("log_type", &identifier)
                .add("appsec_config_key", rc_path)
                .add("rc_config_id", &parsed_key.config_id);

            logs_submitter.submit_log(TelemetryLog {
                level: telemetry::LogLevel::Error,
                identifier,
                message,
                stack_trace: None,
                tags: Some(tags),
                is_sensitive: false,
            });
        } else {
            error!(
                "Can't parse RC path: {:?}; no submission of telemetry log",
                rc_path
            );
        }
    }

    fn log_general_rc_error(&self, error: &anyhow::Error) {
        let message = format!(
            "Failed to apply config for service with config {:?}: {:#}",
            self.fixed_config, error
        );
        warning!("{}", message);
        self.logs_collector.submit_log(TelemetryLog {
            level: telemetry::LogLevel::Error,
            identifier: "rc::client::exception".to_string(),
            message,
            stack_trace: None,
            tags: None,
            is_sensitive: false,
        });
    }
}
impl TelemetryLogsGenerator for Service {
    fn generate_telemetry_logs(&'_ self, submitter: &mut dyn TelemetryLogSubmitter) {
        self.logs_collector.generate_telemetry_logs(submitter);
    }
}
impl TelemetryMetricsGenerator for Service {
    fn generate_telemetry_metrics(&self, submitter: &mut dyn TelemetryMetricSubmitter) {
        self.worker_count.generate_telemetry_metrics(submitter);
        self.take_pending_telemetry_metrics()
            .generate_telemetry_metrics(submitter);
    }
}

#[derive(Eq, PartialEq, Hash, Debug, Clone)]
pub struct ServiceFixedConfig {
    waf_settings: WafSettings,
    rem_cfg_settings: RemoteConfigSettings,
    telemetry_settings: TelemetrySettings,
}

impl ServiceFixedConfig {
    pub fn new(
        waf_settings: WafSettings,
        rem_cfg_settings: RemoteConfigSettings,
        telemetry_settings: TelemetrySettings,
    ) -> Self {
        ServiceFixedConfig {
            waf_settings,
            rem_cfg_settings,
            telemetry_settings,
        }
    }
}

#[derive(Debug, Default, Clone)]
pub struct ConfigSnapshot {
    pub rules_version: Option<String>,
}
impl ConfigSnapshot {
    pub fn new(rules_version: Option<String>) -> Self {
        Self { rules_version }
    }

    pub fn with_new_rules_version(&self, rules_version: Option<String>) -> Self {
        Self { rules_version }
    }
}

// --- Implementation details ---

struct ServiceManagerInner {
    services: HashMap<ServiceFixedConfig, Weak<Service>>,
    last_service: Option<Arc<Service>>,
    failures: HashMap<ServiceFixedConfig, ServiceFailure>,
}

const SERVICE_RETRY_BASE: Duration = Duration::from_millis(500);
const SERVICE_RETRY_MAX: Duration = Duration::from_secs(60);

struct ServiceFailure {
    attempts: u32,
    retry_at: Instant,
    error: String,
}

impl ServiceManagerInner {
    fn cleanup(&mut self) {
        self.services.retain(|_, weak| weak.strong_count() > 0);
    }
}

struct RcUpdateState {
    poller: Option<rc::ConfigPoller>,
    // the path is kept as key to determine if the config has changed, since
    // the version of the config is encoded inside the shm path
    // If it hasn't we can skip reapplying the same config
    last_configs: HashMap<rc::RcPath, PathBuf>,
    pending_telemetry_metrics: TelemetryMetricsCollector,
    pending_init_diagnostics_legacy: Option<InitDiagnosticsLegacy>,
}

// The directory also lists the tracer's products (ASM_FEATURES, APM_TRACING,
// ...); only WAF configs were ever added, so only those can be removed.
fn is_waf_product(rc_path: &rc::RcPath) -> bool {
    rc::ParsedConfigKey::from_rc_path(rc_path)
        .is_some_and(|key| matches!(key.product.as_str(), "asm_dd" | "asm" | "asm_data"))
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

    fn waf_settings(rules_file: &str) -> WafSettings {
        WafSettings {
            rules_file: Some(rules_file.to_string()),
            waf_timeout_us: Some(10000),
            trace_rate_limit: 100,
            obfuscator_key_regex: None,
            obfuscator_value_regex: None,
            schema_extraction: settings::SchemaExtraction {
                enabled: false,
                sampling_period: 1.0,
            },
        }
    }

    fn make_config(id: u32) -> ServiceFixedConfig {
        ServiceFixedConfig::new(
            waf_settings(TEST_RULES_FILE),
            RemoteConfigSettings::new(None),
            TelemetrySettings {
                service_name: format!("test_{}", id),
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

    #[test]
    fn notify_of_rc_updates_updates_every_service_on_the_path() -> anyhow::Result<()> {
        use crate::rc::tests::{shm_create_and_write, shm_create_and_write_config_dir, shm_unlink};
        use base64::Engine;

        let dir = "/helper_rust_notify_all_dir";
        let file = "/helper_rust_notify_all_asm";
        shm_unlink(dir);

        // Same RC path, different telemetry settings: two services.
        let manager = ServiceManager::new();
        let services = [1, 2]
            .iter()
            .map(|&id| {
                let mut config = make_config(id);
                config.rem_cfg_settings = RemoteConfigSettings::new(Some(PathBuf::from(dir)));
                manager.get_service(&config, &mut NoopTelemetrySubmitter)
            })
            .collect::<anyhow::Result<Vec<_>>>()?;
        assert_eq!(manager.service_count(), 2);

        let mut asm =
            br#"{"exclusions":[{"id":"excl","rules_target":[{"rule_id":"blk-001-001"}]}]}"#
                .to_vec();
        // macOS reports shm sizes rounded up to 16 KiB; pad with whitespace so
        // that the JSON parser does not see the zero filler.
        asm.resize(16384, b' ');
        shm_create_and_write(file, &asm)?;
        let rc_path = base64::engine::general_purpose::URL_SAFE_NO_PAD
            .encode("datadog/2/ASM/excl/config".as_bytes());
        let _writer =
            shm_create_and_write_config_dir(dir, "runtime-1", &[format!("{file}:0:{rc_path}")])?;

        manager.notify_of_rc_updates(std::path::Path::new(dir));

        for service in &services {
            assert_eq!(service.waf.config_paths_count(Some("/ASM/")), 1);
        }

        shm_unlink(file);
        shm_unlink(dir);
        Ok(())
    }

    #[test]
    fn failed_service_is_not_rebuilt_before_its_backoff_expires() {
        let manager = ServiceManager::new();
        let config = ServiceFixedConfig::new(
            waf_settings("/nonexistent/rules.json"),
            RemoteConfigSettings::new(None),
            TelemetrySettings::default(),
        );

        let first = manager
            .get_service(&config, &mut NoopTelemetrySubmitter)
            .err()
            .expect("a missing rules file fails the service");
        let second = manager
            .get_service(&config, &mut NoopTelemetrySubmitter)
            .err()
            .expect("still failing");

        assert!(!format!("{first:#}").contains("next retry"));
        assert!(format!("{second:#}").contains("next retry"), "{second:#}");

        // Other configs are unaffected.
        assert!(manager
            .get_service(&make_config(1), &mut NoopTelemetrySubmitter)
            .is_ok());
    }
}
