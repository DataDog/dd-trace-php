use std::{
    cell::Cell,
    sync::{
        atomic::{self, AtomicU64},
        Arc,
    },
    time::{Duration, Instant},
};

use anyhow::{anyhow, Context};
use arc_swap::ArcSwapOption;
use datadog_sidecar::appsec::protocol::{
    AppSecAction, AppSecAddresses, AppSecOutcome, AppSecRaspRule, AppSecRequest, AppSecVerdict,
};
use datadog_sidecar::appsec::AppSecApplication;
use datadog_sidecar::service::{
    telemetry::{InProcessTelemetryClient, InProcessTelemetryClientFactory},
    ConnectionSessionHandle,
};
use libddwaf::{object::WafObjectType, RunnableContext};
use log::{debug, error, info, warning as warn};
use tokio::sync::{mpsc, oneshot};
use tokio_util::sync::CancellationToken;

use crate::{
    service::{
        settings::{application_settings, WafSettings},
        Service, ServiceFixedConfig, ServiceManager,
    },
    telemetry::{
        error_tel_ctx::{update_error_telemetry_context, with_error_telemetry_handle},
        SpanMetaGenerator, SpanMetricsGenerator, SpanMetricsSubmitter, TelemetryLogsGenerator,
        TelemetryMetricsGenerator, TelemetrySidecarLogSubmitter, TelemetrySidecarMetricSubmitter,
    },
};

pub use sidecar_msg::{
    clear_inherited_state, on_application, on_disconnect, on_request, on_session_config,
    start_accepting_messages, stop_accepting_messages, ClientRequest, NewClient, NewClientFn,
};

mod attributes;
pub mod log;
mod metrics;
mod sidecar_msg;

/// The helper's client of one sidecar connection: the requests of one extension thread, one at a
/// time.
pub struct Client {
    pub id: u64,
    service_manager: &'static ServiceManager,
    waf_settings: WafSettings,
    /// The connection's current application, as the sidecar last reported it.
    application: Arc<ArcSwapOption<AppSecApplication>>,
    bound: Option<Bound>,
    telemetry_client_factory: InProcessTelemetryClientFactory,
    /// The sidecar connection of the extension, which telemetry is submitted with.
    session: ConnectionSessionHandle,
    telemetry_client: Option<InProcessTelemetryClient>,
    metrics_last_registered: Cell<Option<Instant>>,
    // Sent with the first request_shutdown, so that the first trace of each
    // client carries it.
    waf_version_sent: bool,
}

static CLIENT_SERIAL: AtomicU64 = AtomicU64::new(1);
impl Client {
    pub fn new(
        service_manager: &'static ServiceManager,
        telemetry_client_factory: InProcessTelemetryClientFactory,
        new_client: NewClient,
    ) -> Self {
        Self {
            id: CLIENT_SERIAL.fetch_add(1, atomic::Ordering::Relaxed),
            service_manager,
            waf_settings: WafSettings::from(&new_client.config),
            application: new_client.application,
            bound: None,
            telemetry_client_factory,
            session: new_client.session,
            telemetry_client: None,
            metrics_last_registered: Cell::new(None),
            waf_version_sent: false,
        }
    }

    pub async fn entrypoint(
        self,
        rx: mpsc::Receiver<ClientRequest>,
        cancel_token: CancellationToken,
    ) {
        // wrap entrypoint with the task locals that allow:
        // - client id in the logs
        // - submission of errors to telemetry (once bound to an application)
        let client_id = self.id;
        let entrypoint_fut = self.do_entrypoint(rx, cancel_token);
        log::with_scoped_client_id(client_id, with_error_telemetry_handle(entrypoint_fut)).await;
    }

    async fn do_entrypoint(
        mut self,
        mut rx: mpsc::Receiver<ClientRequest>,
        cancel_token: CancellationToken,
    ) {
        info!("starting");
        let mut next = None;
        loop {
            let request = match next.take() {
                Some(request) => request,
                None => match self.recv_between_requests(&mut rx, &cancel_token).await {
                    Some(request) => request,
                    None => break,
                },
            };
            next = self.handle(request, &mut rx, &cancel_token).await;
        }
        if cancel_token.is_cancelled() {
            info!("ended: the helper is shutting down");
        } else {
            info!("ended: its connection closed");
        }
    }

    /// Requests may not come for long, e.g. while the extension has AppSec inactive, so the
    /// telemetry the service generates applying RC updates is submitted meanwhile.
    async fn recv_between_requests(
        &mut self,
        rx: &mut mpsc::Receiver<ClientRequest>,
        cancel_token: &CancellationToken,
    ) -> Option<ClientRequest> {
        loop {
            let Some(bound) = self.bound.as_mut() else {
                return recv(rx, cancel_token).await;
            };
            tokio::select! {
                request = recv(rx, cancel_token) => return request,
                Ok(()) = bound.rc_updates.changed() => {}
            }
            submit_service_telemetry(self, self.service());
        }
    }

    /// Handles a request outside of an AppSec request. Returns a request_init that interrupted
    /// the request it started.
    async fn handle(
        &mut self,
        ClientRequest {
            request,
            response_tx,
        }: ClientRequest,
        rx: &mut mpsc::Receiver<ClientRequest>,
        cancel_token: &CancellationToken,
    ) -> Option<ClientRequest> {
        match request {
            AppSecRequest::Init {
                addresses,
                keep_bound_application,
            } => {
                self.run_request(
                    addresses,
                    keep_bound_application,
                    response_tx,
                    rx,
                    cancel_token,
                )
                .await
            }
            // The extension tracks the requests it started, and forgets them on fork: this is
            // a client the sidecar restarted, or an extension bug.
            AppSecRequest::Exec { .. } | AppSecRequest::Shutdown { .. } => {
                let stage = if matches!(request, AppSecRequest::Exec { .. }) {
                    "request_exec"
                } else {
                    "request_shutdown"
                };
                error!("{} without request_init", stage);
                respond(
                    response_tx,
                    AppSecVerdict::Error(format!("{stage} without request_init")),
                );
                None
            }
        }
    }

    async fn run_request(
        &mut self,
        addresses: AppSecAddresses,
        keep_bound_application: bool,
        response_tx: oneshot::Sender<AppSecVerdict>,
        rx: &mut mpsc::Receiver<ClientRequest>,
        cancel_token: &CancellationToken,
    ) -> Option<ClientRequest> {
        if let Err(e) = self.bind(keep_bound_application) {
            warn!("No service for this request: {:#}", e);
            respond(response_tx, AppSecVerdict::Unavailable);
            return None;
        }

        let mut req_ctx = {
            let service = self.service();
            ReqContext::new(service, service.config_snapshot().clone())
        };

        let mut next = None;
        let verdict = self.request_init(&mut req_ctx, addresses);
        let failed = matches!(verdict, AppSecVerdict::Error(_));
        respond(response_tx, verdict);
        if !failed {
            next = self.request_loop(&mut req_ctx, rx, cancel_token).await;
        }

        submit_context_telemetry_metrics(self, &mut req_ctx);
        submit_service_telemetry(self, self.service());

        // The application the request ended with is known now.
        if keep_bound_application && next.is_none() {
            if let Err(e) = self.bind(false) {
                warn!("Could not bind to the current application: {:#}", e);
            }
        }
        next
    }

    fn request_init(
        &mut self,
        req_ctx: &mut ReqContext,
        addresses: AppSecAddresses,
    ) -> AppSecVerdict {
        let result = decode_addresses(&addresses, 0)
            .and_then(|data| req_ctx.run_waf(data, &RequestExecOptions::regular()));
        match result {
            Ok(result) => {
                let force_keep = req_ctx.should_force_keep(self.service(), result.waf_keep);
                AppSecVerdict::Ok(result.into_outcome(force_keep))
            }
            Err(e) => fail_request("request_init", e),
        }
    }

    /// Serves the requests until the request_shutdown. Returns a request_init arriving instead.
    async fn request_loop(
        &mut self,
        req_ctx: &mut ReqContext,
        rx: &mut mpsc::Receiver<ClientRequest>,
        cancel_token: &CancellationToken,
    ) -> Option<ClientRequest> {
        loop {
            let ClientRequest {
                request,
                response_tx,
            } = recv(rx, cancel_token).await?;
            match request {
                AppSecRequest::Exec { addresses, rasp } => {
                    let verdict = self.request_exec(req_ctx, addresses, rasp);
                    let failed = matches!(verdict, AppSecVerdict::Error(_));
                    respond(response_tx, verdict);
                    if failed {
                        return None;
                    }
                }
                AppSecRequest::Shutdown {
                    addresses,
                    api_sec_samp_key,
                    input_truncated,
                } => {
                    let verdict = self.request_shutdown(
                        req_ctx,
                        addresses,
                        api_sec_samp_key,
                        input_truncated,
                    );
                    respond(response_tx, verdict);
                    return None;
                }
                init @ AppSecRequest::Init { .. } => {
                    // The previous request ended without request_shutdown, e.g. with a bailout.
                    debug!("request_init during a request; abandoning the previous request");
                    return Some(ClientRequest {
                        request: init,
                        response_tx,
                    });
                }
            }
        }
    }

    fn request_exec(
        &mut self,
        req_ctx: &mut ReqContext,
        addresses: AppSecAddresses,
        rasp: Option<AppSecRaspRule>,
    ) -> AppSecVerdict {
        let data = match decode_addresses(&addresses, 0) {
            Ok(data) => data,
            Err(e) => return fail_request("request_exec", e),
        };
        let options = RequestExecOptions {
            run_type: match rasp {
                Some(AppSecRaspRule { rule, variant }) if !rule.is_empty() => {
                    WafRunType::RaspRule {
                        rule_type: rule,
                        rule_variant: variant.filter(|v| !v.is_empty()),
                    }
                }
                _ => WafRunType::NonRasp,
            },
            // server.request.* addresses may be overridden in request_exec on the main context;
            // anything else runs on a transient subcontext.
            transient: !has_server_request_address(&data),
        };
        if !options.transient {
            debug!(
                "Running WAF on the main WAF context because server.request.* variables are \
                 present: {:?}",
                data
            );
        }
        match req_ctx.run_waf(data, &options) {
            Ok(result) => {
                let force_keep = req_ctx.should_force_keep(self.service(), result.waf_keep);
                AppSecVerdict::Ok(result.into_outcome(force_keep))
            }
            Err(e) => fail_request("request_exec", e),
        }
    }

    fn request_shutdown(
        &mut self,
        req_ctx: &mut ReqContext,
        addresses: AppSecAddresses,
        api_sec_samp_key: u64,
        input_truncated: bool,
    ) -> AppSecVerdict {
        let extract_schema = self.service().should_extract_schema(api_sec_samp_key);
        let data = match decode_addresses(&addresses, usize::from(extract_schema)) {
            Ok(mut data) => {
                if extract_schema {
                    use libddwaf::waf_map;
                    let context_processor = waf_map! {("extract-schema", true)};
                    let last = data.len() - 1;
                    data[last] = ("waf.context.processor", context_processor).into();
                }
                data
            }
            Err(e) => return fail_request("request_shutdown", e),
        };

        let result = match req_ctx.run_waf(data, &RequestExecOptions::regular()) {
            Ok(result) => result,
            Err(e) => return fail_request("request_shutdown", e),
        };

        req_ctx.record_shutdown_context(input_truncated);

        // span metrics / meta
        let mut span_submitter = metrics::CollectingMetricsSubmitter::default();
        req_ctx.generate_span_metrics(&mut span_submitter);
        req_ctx.generate_meta(&mut span_submitter);
        // WAF (re)initialization diagnostics go to whichever request finishes first after the
        // service built or updated its WAF.
        if let Some(diag) = self.service().take_pending_init_diagnostics_legacy() {
            diag.generate_span_metrics(&mut span_submitter);
        }
        if !self.waf_version_sent {
            span_submitter.submit_meta(
                crate::telemetry::WAF_VERSION,
                Service::waf_version().to_string(),
            );
            self.waf_version_sent = true;
        }

        let force_keep = req_ctx.should_force_keep(self.service(), result.waf_keep);
        let mut outcome = result.into_outcome(force_keep);
        outcome.meta = span_submitter
            .take_meta()
            .into_iter()
            .map(|(k, v)| (k.into_owned(), v))
            .collect();
        outcome.metrics = span_submitter
            .take_metrics()
            .into_iter()
            .map(|(k, v)| (k.into_owned(), v))
            .collect();
        cap_outcome_size(&mut outcome);
        AppSecVerdict::Ok(outcome)
    }

    /// Binds the client to the connection's current application, unless `keep_bound` and some
    /// application is bound already. A failure keeps the previous binding, if any.
    fn bind(&mut self, keep_bound: bool) -> anyhow::Result<()> {
        let latest = self.application.load_full();
        let application = match (&self.bound, latest) {
            (Some(_), _) if keep_bound => return Ok(()),
            (Some(bound), Some(latest)) if bound.application == *latest => return Ok(()),
            (Some(_), None) => return Ok(()),
            (_, Some(latest)) => (*latest).clone(),
            // No application was reported: requests are not attributed to any service.
            (None, None) => AppSecApplication {
                service: String::new(),
                env: String::new(),
                version: String::new(),
                remote_config_path: None,
            },
        };

        let (rem_cfg_settings, telemetry_settings) = application_settings(&application);
        let config = ServiceFixedConfig::new(
            self.waf_settings.clone(),
            rem_cfg_settings,
            telemetry_settings.clone(),
        );
        debug!("Binding to application {:?}: {:?}", application, config);

        let telemetry = match &self.telemetry_client {
            Some(telemetry) => telemetry.with_new_service_env(
                telemetry_settings.service_name.clone(),
                telemetry_settings.env_name.clone(),
            ),
            None => self.telemetry_client_factory.create_client(
                self.session.clone(),
                telemetry_settings.service_name,
                telemetry_settings.env_name,
            ),
        };

        // The old service may go away at the end, so submit its telemetry now.
        if let Some(bound) = &self.bound {
            submit_service_telemetry(self, &bound.service);
        }
        self.metrics_last_registered.set(None);

        // Creating a service generates telemetry (more likely we're just fetching the existing
        // one, though).
        let mut tel_metric_submitter =
            TelemetrySidecarMetricSubmitter::create(&telemetry, &self.metrics_last_registered);
        let service = self
            .service_manager
            .get_service(&config, &mut *tel_metric_submitter);
        drop(tel_metric_submitter);

        match service {
            Ok(service) => {
                update_error_telemetry_context(telemetry.clone());
                self.bound = Some(Bound {
                    application,
                    rc_updates: service.subscribe_rc_updates(),
                    service: TrackedService::new(service),
                });
                self.telemetry_client = Some(telemetry);
                Ok(())
            }
            Err(e) if self.bound.is_some() => {
                let fd_diagnostics = self.service_manager.describe_fd_exhaustion(&e);
                error!(
                    "Failed to get the service of application {:?}: {:#}; will continue running \
                     with the old service!{}",
                    application, e, fd_diagnostics
                );
                Ok(())
            }
            Err(e) => Err(e.context("no service for the application")),
        }
    }

    /// Only called once bound.
    fn service(&self) -> &Service {
        &self
            .bound
            .as_ref()
            .expect("the client is bound during requests")
            .service
    }
}

/// The application a client serves, and its service.
struct Bound {
    application: AppSecApplication,
    service: TrackedService,
    rc_updates: tokio::sync::watch::Receiver<u64>,
}

/// Smart pointer that tracks worker count for a service.
/// Increments on creation, decrements on drop.
struct TrackedService {
    service: Arc<Service>,
}

impl TrackedService {
    fn new(service: Arc<Service>) -> Self {
        service.increment_worker_count();
        Self { service }
    }
}

impl Drop for TrackedService {
    fn drop(&mut self) {
        self.service.decrement_worker_count();
    }
}

impl std::ops::Deref for TrackedService {
    type Target = Service;
    fn deref(&self) -> &Self::Target {
        &self.service
    }
}

/// Starts a client task for each connection the sidecar configures with AppSec.
pub fn new_client_fn(
    service_manager: &'static ServiceManager,
    telemetry: InProcessTelemetryClientFactory,
    runtime_handle: tokio::runtime::Handle,
    cancel_token: CancellationToken,
    task_tracker: tokio_util::task::TaskTracker,
) -> NewClientFn {
    Box::new(move |new_client| {
        let client = Client::new(service_manager, telemetry.clone(), new_client);
        info!("Created client: id {}", client.id);
        let client_id = client.id;

        let (tx, rx) = mpsc::channel(5);
        let cancel_token = cancel_token.clone();
        let client_future = task_tracker.track_future(async move {
            client.entrypoint(rx, cancel_token).await;
            debug!("Client future of client {client_id} completed");
        });
        runtime_handle.spawn(client_future);
        tx
    })
}

async fn recv(
    rx: &mut mpsc::Receiver<ClientRequest>,
    cancel_token: &CancellationToken,
) -> Option<ClientRequest> {
    tokio::select! {
        request = rx.recv() => {
            if let Some(request) = &request {
                debug!("Received request: {:?}", request.request);
            }
            request
        }
        _ = cancel_token.cancelled() => {
            info!("Cancellation during client recv");
            None
        }
    }
}

fn respond(response_tx: oneshot::Sender<AppSecVerdict>, verdict: AppSecVerdict) {
    debug!("Sending verdict: {:?}", verdict);
    if response_tx.send(verdict).is_err() {
        debug!("The sidecar gave up waiting for the verdict");
    }
}

fn fail_request(stage: &str, error: anyhow::Error) -> AppSecVerdict {
    error!("{} failed: {:#}", stage, error);
    AppSecVerdict::Error(format!("{stage} failed: {error:#}"))
}

fn submit_service_telemetry(client: &Client, service: &Service) {
    if let Some(telemetry) = &client.telemetry_client {
        debug!("Submitting service telemetry to sidecar");
        let mut submitter = TelemetrySidecarLogSubmitter::create(telemetry);
        service.generate_telemetry_logs(&mut *submitter);

        let mut submitter =
            TelemetrySidecarMetricSubmitter::create(telemetry, &client.metrics_last_registered);
        service.generate_telemetry_metrics(&mut *submitter);
    } else {
        debug!("Cannot submit service telemetry: telemetry client is not bound");
    }
}

fn submit_context_telemetry_metrics(client: &Client, req_ctx: &mut ReqContext) {
    let Some(telemetry) = &client.telemetry_client else {
        warn!("Cannot submit context telemetry metrics: telemetry client is not bound");
        return;
    };
    let mut tel_metric_submitter =
        TelemetrySidecarMetricSubmitter::create(telemetry, &client.metrics_last_registered);

    let waf_metrics = req_ctx.take_waf_metrics();
    waf_metrics.generate_telemetry_metrics(&mut *tel_metric_submitter);
}

/// Decodes the MessagePack map of the addresses, leaving `room` entries at the end for the caller
/// to fill. No data means no addresses.
fn decode_addresses(
    addresses: &AppSecAddresses,
    room: usize,
) -> anyhow::Result<libddwaf::object::WafMap> {
    let data = addresses.0.as_slice();
    if data.is_empty() {
        return Ok(libddwaf::object::WafMap::new(room)?);
    }
    let map: libddwaf::object::WafMap =
        rmp_serde::from_slice(data).context("Malformed request addresses")?;
    if room == 0 {
        return Ok(map);
    }
    let mut extended = libddwaf::object::WafMap::new(map.len() + room)?;
    for (i, entry) in map.into_iter().enumerate() {
        extended[i] = entry;
    }
    Ok(extended)
}

/// Verdicts travel back in one IPC message, which must stay below the sidecar's message size
/// limit. Schemas go first, then all triggers but the first.
fn cap_outcome_size(outcome: &mut AppSecOutcome) {
    fn size(outcome: &AppSecOutcome) -> usize {
        let pairs: usize = outcome.meta.iter().map(|(k, v)| k.len() + v.len()).sum();
        let triggers: usize = outcome.triggers.iter().map(String::len).sum();
        pairs + triggers + outcome.metrics.len() * 64
    }

    let before = size(outcome);
    if before <= MAX_OUTCOME_SIZE {
        return;
    }
    outcome
        .meta
        .retain(|(key, _)| !key.starts_with("_dd.appsec.s."));
    let mut dropped = "API security schemas";
    if size(outcome) > MAX_OUTCOME_SIZE {
        outcome.triggers.truncate(1);
        dropped = "API security schemas and extra triggers";
    }
    warn!(
        "AppSec verdict of {} bytes exceeds {} bytes; dropped {}",
        before, MAX_OUTCOME_SIZE, dropped
    );
}

const MAX_OUTCOME_SIZE: usize = 3 * 1024 * 1024;

#[derive(Debug)]
struct RequestExecOptions {
    run_type: WafRunType,
    /// Run on a subcontext dropped right after.
    transient: bool,
}
impl RequestExecOptions {
    fn regular() -> Self {
        Self {
            run_type: WafRunType::NonRasp,
            transient: false,
        }
    }
}

#[derive(Debug, PartialEq)]
enum WafRunType {
    NonRasp,
    RaspRule {
        rule_type: String,
        rule_variant: Option<String>,
    },
}

struct WafRunResult {
    triggers: Vec<String>,
    actions: Vec<AppSecAction>,
    waf_keep: bool,
}

impl WafRunResult {
    fn into_outcome(self, force_keep: bool) -> AppSecOutcome {
        AppSecOutcome {
            actions: self.actions,
            triggers: self.triggers,
            force_keep,
            meta: vec![],
            metrics: vec![],
        }
    }
}

struct ReqContext {
    waf_ctx: libddwaf::Context,
    config_snapshot: Arc<crate::service::ConfigSnapshot>,
    limiter_result: Option<bool>,
    waf_metrics: metrics::WafMetrics,
    waf_attributes: attributes::CollectedWafAttributes,
    waf_timeout: Duration,
}
impl ReqContext {
    const DEFAULT_WAF_TIMEOUT: Duration = Duration::from_millis(50);
    const MAX_PLAIN_SCHEMA_ALLOWED: usize = 260;
    const MAX_SCHEMA_SIZE: usize = 25000;

    fn new(service: &Service, config_snapshot: Arc<crate::service::ConfigSnapshot>) -> Self {
        let rules_version = config_snapshot.rules_version.clone();
        let waf_timeout = service
            .configured_waf_timeout()
            .unwrap_or(Self::DEFAULT_WAF_TIMEOUT);

        Self {
            waf_ctx: service.new_context(),
            config_snapshot,
            limiter_result: None,
            waf_metrics: metrics::WafMetrics::new(rules_version),
            waf_attributes: attributes::CollectedWafAttributes::new(
                Self::MAX_PLAIN_SCHEMA_ALLOWED,
                Self::MAX_SCHEMA_SIZE,
            ),
            waf_timeout,
        }
    }

    /// Records a WAF evaluation error against the appropriate metric: appsec.waf.error
    /// for non-RASP runs, appsec.rasp.error for RASP runs (RFC-1012).
    fn record_eval_error(&mut self, error_code: i32, options: &RequestExecOptions) {
        match &options.run_type {
            WafRunType::NonRasp => self.waf_metrics.record_non_rasp_error_eval(error_code),
            WafRunType::RaspRule {
                rule_type,
                rule_variant,
            } => self.waf_metrics.record_rasp_error_eval(
                error_code,
                rule_type,
                rule_variant.as_deref().unwrap_or(""),
            ),
        }
    }

    fn run_waf(
        &mut self,
        data: libddwaf::object::WafMap,
        options: &RequestExecOptions,
    ) -> anyhow::Result<WafRunResult> {
        debug!("Running WAF with: {:?}, options: {:?}", data, options);

        let waf_timeout = self.waf_timeout;
        // Evaluation is CPU-bound and bounded by the WAF timeout. Running it directly avoids
        // handing the runtime worker core to another thread for every request phase.
        let maybe_res = if options.transient {
            let mut subctx = self
                .waf_ctx
                .new_subcontext()
                .map_err(|e| anyhow!("Failed to create subcontext: {}", e))?;
            subctx.run(data, waf_timeout)
        } else {
            self.waf_ctx.run(data, waf_timeout)
        };
        let res = match maybe_res {
            Ok(res) => res,
            Err(err) => {
                let error_code = match err {
                    libddwaf::RunError::InternalError => -3i32,
                    libddwaf::RunError::InvalidObject => -2i32,
                    libddwaf::RunError::InvalidArgument => -1i32,
                    _ => -127i32,
                };
                self.record_eval_error(error_code, options);
                anyhow::bail!("WAF evaluation error: {}", err);
            }
        };

        debug!("WAF run result: {:?}", res);
        let run_output = match res {
            libddwaf::RunResult::Match(result) => result,
            libddwaf::RunResult::NoMatch(result) => result,
        };

        let triggers = match run_output.events() {
            Some(events) => convert_events_to_json(events.value())?,
            None => Vec::new(),
        };
        let actions = match run_output.actions() {
            Some(actions) => convert_actions(actions.value(), !triggers.is_empty())?,
            None => Vec::new(),
        };
        let waf_keep = run_output.keep();

        if let Some(attributes) = run_output.attributes() {
            for attr_kv in attributes.value().iter() {
                self.waf_attributes.add_attribute(attr_kv);
            }
        }

        match &options.run_type {
            WafRunType::NonRasp => {
                self.waf_metrics.record_non_rasp_eval(&run_output);
            }
            WafRunType::RaspRule {
                rule_type,
                rule_variant,
            } => {
                self.waf_metrics.record_rasp_eval(
                    rule_type,
                    rule_variant.as_deref().unwrap_or(""),
                    &run_output,
                );
            }
        }

        Ok(WafRunResult {
            triggers,
            actions,
            waf_keep,
        })
    }

    fn should_force_keep(&mut self, service: &Service, waf_keep: bool) -> bool {
        // The limiter slot is only consumed when the WAF triggered; clean requests
        // must not burn a slot even if this function is called multiple times.
        if !waf_keep {
            return false;
        }
        match self.limiter_result {
            Some(result) => result,
            None => {
                let result = service.should_force_keep();
                self.limiter_result = Some(result);
                result
            }
        }
    }

    pub fn record_shutdown_context(&mut self, input_truncated: bool) {
        self.waf_metrics.set_input_truncated(input_truncated);
    }

    pub fn take_waf_metrics(&mut self) -> metrics::WafMetrics {
        self.waf_metrics
            .set_rate_limited(matches!(self.limiter_result, Some(false)));

        std::mem::take(&mut self.waf_metrics)
    }
}

impl crate::telemetry::SpanMetricsGenerator for ReqContext {
    fn generate_span_metrics(&'_ self, submitter: &mut dyn crate::telemetry::SpanMetricsSubmitter) {
        self.waf_metrics.generate_span_metrics(submitter);
        self.waf_attributes.generate_span_metrics(submitter);
    }
}

impl crate::telemetry::SpanMetaGenerator for ReqContext {
    fn generate_meta(&'_ self, submitter: &mut dyn crate::telemetry::SpanMetricsSubmitter) {
        // EVENT_RULES_VERSION is sent on every request_shutdown
        // (WAF_VERSION and the init diagnostics only after changes)
        if let Some(rules_ver) = self.config_snapshot.rules_version.as_deref() {
            submitter.submit_meta(crate::telemetry::EVENT_RULES_VERSION, rules_ver.to_string());
        }
    }
}

fn convert_events_to_json(events: &libddwaf::object::WafArray) -> anyhow::Result<Vec<String>> {
    events
        .iter()
        .try_fold(Vec::new(), |mut acc, event| -> anyhow::Result<_> {
            let event_str = serde_json::to_string(event)?;
            acc.push(event_str);
            Ok(acc)
        })
}

// the extension expects different names in the protocol message
fn map_action_name(waf_action: &str) -> Option<&'static str> {
    match waf_action {
        "block_request" => Some("block"),
        "redirect_request" => Some("redirect"),
        "generate_stack" => Some("stack_trace"),
        "generate_schema" => Some("extract_schema"),
        // "monitor" is reserved but not used in the WAF
        _ => None,
    }
}

// convert ddwaf map {<action name>: {<param>: <value>}} to Vec<AppSecAction>,
// with some massaging to make inject "record" action.
fn convert_actions(
    actions: &libddwaf::object::WafMap,
    has_triggers: bool,
) -> anyhow::Result<Vec<AppSecAction>> {
    let mut conv_actions =
        actions
            .iter()
            .try_fold(Vec::new(), |mut acc, kv| -> anyhow::Result<_> {
                let waf_action_name = kv.key_str().map_err(|e| anyhow!(e.to_string()))?;
                let action = match map_action_name(waf_action_name) {
                    Some(mapped) => mapped,
                    None => {
                        warn!("Unknown WAF action type: {}", waf_action_name);
                        return Ok(acc); // skip unknown actions
                    }
                };
                let parameters = kv
                    .value()
                    .as_type::<libddwaf::object::WafMap>()
                    .ok_or_else(|| anyhow!("Action parameter map not a map"))?
                    .iter()
                    .try_fold(Vec::new(), |mut acc, kv| -> anyhow::Result<_> {
                        let key = kv.key_str().map_err(|e| anyhow!(e.to_string()))?;
                        let value = kv.value();
                        let value_str: String = match value.object_type() {
                            WafObjectType::String => value
                                .as_type::<libddwaf::object::WafString>()
                                .expect("We just checked it was a string")
                                .as_str()
                                .with_context(|| "Action parameter value is not a UTF-8 String")?
                                .into(),
                            WafObjectType::Unsigned => value
                                .as_type::<libddwaf::object::WafUnsigned>()
                                .expect("We just checked it was an unsigned")
                                .value()
                                .to_string(),
                            _ => {
                                anyhow::bail!(
                                "Action parameter value is not a string or unsigned: got {:?}. \
                                 Full actions: {:?}",
                                kv.value(),
                                actions
                            )
                            }
                        };
                        acc.push((key.to_owned(), value_str));
                        Ok(acc)
                    })?;
                acc.push(AppSecAction {
                    action: action.to_owned(),
                    parameters,
                });
                Ok(acc)
            })?;
    maybe_inject_record_action(&mut conv_actions, has_triggers);
    Ok(conv_actions)
}

/// Injects a "record" action if there are triggers but no actions or
/// if there is a stack_trace action with no block/redirect/record action.
/// The extension only saves triggers if there is a record action.
fn maybe_inject_record_action(actions: &mut Vec<AppSecAction>, has_triggers: bool) {
    let record = || AppSecAction {
        action: "record".to_owned(),
        parameters: vec![],
    };
    if actions.is_empty() && has_triggers {
        actions.push(record());
    }

    let mut event_action = false;
    let mut stack_trace = false;

    for action in &*actions {
        match action.action.as_str() {
            "block" | "redirect" | "record" => {
                event_action = true;
            }
            "stack_trace" => {
                stack_trace = true;
            }
            _ => {}
        }
    }

    if !event_action && stack_trace {
        // Stacktrace needs to send a record as well so Appsec event is generated
        actions.push(record());
    }
}

fn has_server_request_address(data: &libddwaf::object::WafMap) -> bool {
    data.iter().any(|kv| {
        kv.key_str()
            .map(|k| k.starts_with("server.request."))
            .unwrap_or(false)
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use datadog_sidecar::appsec::protocol::{AppSecBytes, AppSecSessionConfig};
    use datadog_sidecar::service::InternalTelemetryActions;

    const TEST_RULES_FILE: &str = concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/src/service/testdata/minimal_rules.json"
    );
    // Detects the Arachni scanner of attack().
    const RECOMMENDED_RULES_FILE: &str =
        concat!(env!("CARGO_MANIFEST_DIR"), "/../recommended.json");

    fn session_config(rules_file: &str) -> AppSecSessionConfig {
        AppSecSessionConfig {
            rules_file: rules_file.to_owned(),
            waf_timeout_us: 0,
            trace_rate_limit: 100,
            obfuscator_key_regex: String::new(),
            obfuscator_value_regex: String::new(),
            schema_extraction_enabled: false,
            schema_extraction_sampling_period: 30.0,
            helper_log_file: vec![],
            helper_log_level: "info".to_owned(),
        }
    }

    fn application(service: &str) -> AppSecApplication {
        AppSecApplication {
            service: service.to_owned(),
            env: "env".to_owned(),
            version: String::new(),
            remote_config_path: None,
        }
    }

    /// A client, its application slot and the receiver its telemetry goes to.
    fn client(
        rules_file: &str,
    ) -> (
        Client,
        Arc<ArcSwapOption<AppSecApplication>>,
        mpsc::Receiver<InternalTelemetryActions>,
    ) {
        let service_manager: &'static ServiceManager = Box::leak(Box::new(ServiceManager::new()));
        let (telemetry, telemetry_rx) = InProcessTelemetryClientFactory::new_for_testing(4096);
        let application = Arc::new(ArcSwapOption::empty());
        let client = Client::new(
            service_manager,
            telemetry,
            NewClient {
                session: ConnectionSessionHandle::default(),
                config: session_config(rules_file),
                application: application.clone(),
            },
        );
        (client, application, telemetry_rx)
    }

    fn set(slot: &ArcSwapOption<AppSecApplication>, service: &str) {
        slot.store(Some(Arc::new(application(service))));
    }

    fn bound(client: &Client) -> &str {
        &client.bound.as_ref().expect("bound").application.service
    }

    struct TestClient {
        tx: mpsc::Sender<ClientRequest>,
        _telemetry: mpsc::Receiver<InternalTelemetryActions>,
    }

    impl TestClient {
        fn start(rules_file: &str) -> Self {
            let (client, application, telemetry) = client(rules_file);
            set(&application, "svc");
            let (tx, rx) = mpsc::channel(5);
            tokio::spawn(client.entrypoint(rx, CancellationToken::new()));
            TestClient {
                tx,
                _telemetry: telemetry,
            }
        }

        async fn request(&self, request: AppSecRequest) -> AppSecVerdict {
            let (response_tx, response_rx) = oneshot::channel();
            self.tx
                .send(ClientRequest {
                    request,
                    response_tx,
                })
                .await
                .unwrap();
            response_rx.await.unwrap()
        }
    }

    fn addresses(map: serde_json::Value) -> AppSecAddresses {
        AppSecAddresses(AppSecBytes(rmp_serde::to_vec(&map).unwrap()))
    }

    fn attack() -> AppSecAddresses {
        addresses(serde_json::json!({
            "server.request.headers.no_cookies": {"user-agent": "Arachni/v1"}
        }))
    }

    fn init(keep_bound_application: bool) -> AppSecRequest {
        AppSecRequest::Init {
            addresses: attack(),
            keep_bound_application,
        }
    }

    fn shutdown() -> AppSecRequest {
        AppSecRequest::Shutdown {
            addresses: AppSecAddresses::default(),
            api_sec_samp_key: 0,
            input_truncated: false,
        }
    }

    fn outcome(verdict: AppSecVerdict) -> AppSecOutcome {
        match verdict {
            AppSecVerdict::Ok(outcome) => outcome,
            other => panic!("expected an outcome, got {other:?}"),
        }
    }

    fn meta<'a>(outcome: &'a AppSecOutcome, key: &str) -> Option<&'a str> {
        outcome
            .meta
            .iter()
            .find(|(k, _)| k == key)
            .map(|(_, v)| v.as_str())
    }

    #[tokio::test]
    async fn rc_update_telemetry_is_submitted_without_waiting_for_a_request() -> anyhow::Result<()>
    {
        use crate::rc::tests::{shm_create_and_write, shm_create_and_write_config_dir, shm_unlink};
        use base64::Engine;
        use datadog_sidecar::service::telemetry::InternalTelemetryAction;
        use libdd_telemetry::worker::TelemetryActions;

        let dir = "/helper_rust_idle_client_rc_dir";
        let file = "/helper_rust_idle_client_asm_dd";
        shm_unlink(dir);

        let (client, application_slot, mut telemetry) = client(TEST_RULES_FILE);
        let service_manager = client.service_manager;
        application_slot.store(Some(Arc::new(AppSecApplication {
            remote_config_path: Some(std::ffi::CString::new(dir)?),
            ..application("svc")
        })));
        let (tx, rx) = mpsc::channel(5);
        tokio::spawn(client.entrypoint(rx, CancellationToken::new()));
        let client = TestClient {
            tx,
            _telemetry: mpsc::channel(1).1,
        };
        outcome(client.request(init(false)).await);
        outcome(client.request(shutdown()).await);
        while telemetry.try_recv().is_ok() {}

        let mut malformed = b"this is not JSON".to_vec();
        // macOS reports shm sizes rounded up to 16 KiB.
        malformed.resize(16384, b' ');
        shm_create_and_write(file, &malformed)?;
        let rc_path = base64::engine::general_purpose::URL_SAFE_NO_PAD
            .encode("datadog/2/ASM_DD/malformed/config".as_bytes());
        let _writer =
            shm_create_and_write_config_dir(dir, "runtime-1", &[format!("{file}:0:{rc_path}")])?;
        service_manager.notify_of_rc_updates(std::path::Path::new(dir));

        let logged = tokio::time::timeout(Duration::from_secs(5), async {
            while let Some(submitted) = telemetry.recv().await {
                let exception = submitted.actions.iter().any(|action| {
                    matches!(
                        action,
                        InternalTelemetryAction::TelemetryAction(TelemetryActions::AddLog((_, log)))
                            if log.tags.contains("log_type:rc::asm_dd::exception")
                    )
                });
                if exception {
                    return true;
                }
            }
            false
        })
        .await;

        shm_unlink(file);
        shm_unlink(dir);
        assert_eq!(logged, Ok(true), "the RC exception log reaches telemetry");
        Ok(())
    }

    #[tokio::test]
    async fn request_runs_the_waf_and_reports_the_waf_version_once() {
        let client = TestClient::start(RECOMMENDED_RULES_FILE);

        let started = outcome(client.request(init(false)).await);
        assert!(!started.triggers.is_empty(), "the attack is detected");
        assert!(started.actions.iter().any(|a| a.action == "record"));

        let first = outcome(client.request(shutdown()).await);
        assert!(meta(&first, "_dd.appsec.waf.version").is_some());
        assert!(first
            .metrics
            .iter()
            .any(|(k, _)| k == "_dd.appsec.event_rules.loaded"));

        outcome(client.request(init(false)).await);
        let second = outcome(client.request(shutdown()).await);
        assert!(meta(&second, "_dd.appsec.waf.version").is_none());
        assert!(!second
            .metrics
            .iter()
            .any(|(k, _)| k == "_dd.appsec.event_rules.loaded"));
    }

    #[tokio::test]
    async fn requests_outside_of_a_request_fail() {
        let client = TestClient::start(TEST_RULES_FILE);
        assert!(matches!(
            client.request(shutdown()).await,
            AppSecVerdict::Error(_)
        ));
        assert!(matches!(
            client
                .request(AppSecRequest::Exec {
                    addresses: AppSecAddresses::default(),
                    rasp: None,
                })
                .await,
            AppSecVerdict::Error(_)
        ));
        // A request still works afterwards.
        outcome(client.request(init(false)).await);
        outcome(client.request(shutdown()).await);
    }

    #[tokio::test]
    async fn request_exec_runs_during_a_request() {
        let client = TestClient::start(TEST_RULES_FILE);
        outcome(client.request(init(false)).await);
        let exec = outcome(
            client
                .request(AppSecRequest::Exec {
                    addresses: attack(),
                    rasp: Some(AppSecRaspRule {
                        rule: "lfi".to_owned(),
                        variant: None,
                    }),
                })
                .await,
        );
        assert!(exec.meta.is_empty());
        outcome(client.request(shutdown()).await);
    }

    #[tokio::test]
    async fn request_init_during_a_request_starts_over() {
        let client = TestClient::start(TEST_RULES_FILE);
        outcome(client.request(init(false)).await);
        outcome(client.request(init(false)).await);
        outcome(client.request(shutdown()).await);
        assert!(matches!(
            client.request(shutdown()).await,
            AppSecVerdict::Error(_)
        ));
    }

    #[tokio::test]
    async fn broken_rules_make_requests_unavailable() {
        let client = TestClient::start("/nonexistent/rules.json");
        assert_eq!(
            client.request(init(false)).await,
            AppSecVerdict::Unavailable
        );
        // No request was started.
        assert!(matches!(
            client.request(shutdown()).await,
            AppSecVerdict::Error(_)
        ));
    }

    #[tokio::test]
    async fn binding_follows_the_application_unless_kept() {
        let (mut client, application, _telemetry) = client(TEST_RULES_FILE);

        client.bind(false).unwrap();
        assert_eq!(bound(&client), "");

        set(&application, "first");
        client.bind(true).unwrap();
        assert_eq!(bound(&client), "", "kept while some application is bound");
        client.bind(false).unwrap();
        assert_eq!(bound(&client), "first");

        set(&application, "second");
        client.bind(false).unwrap();
        assert_eq!(bound(&client), "second");

        // An application that is not reported anymore keeps the binding.
        application.store(None);
        client.bind(false).unwrap();
        assert_eq!(bound(&client), "second");
    }

    /// Runs a request on `client`, switching the application to `during` once the request
    /// started.
    async fn request_switching_application(
        client: &mut Client,
        application: &ArcSwapOption<AppSecApplication>,
        keep_bound_application: bool,
        during: &str,
    ) {
        let (tx, mut rx) = mpsc::channel(5);
        let (init_tx, init_rx) = oneshot::channel();
        let cancel = CancellationToken::new();
        let drive = async {
            outcome(init_rx.await.unwrap());
            set(application, during);
            let (shutdown_tx, shutdown_rx) = oneshot::channel();
            tx.send(ClientRequest {
                request: shutdown(),
                response_tx: shutdown_tx,
            })
            .await
            .unwrap();
            outcome(shutdown_rx.await.unwrap());
        };
        let (next, ()) = tokio::join!(
            client.run_request(attack(), keep_bound_application, init_tx, &mut rx, &cancel),
            drive
        );
        assert!(next.is_none());
    }

    #[tokio::test]
    async fn kept_binding_moves_to_the_application_a_request_ends_with() {
        let (mut client, application, _telemetry) = client(TEST_RULES_FILE);
        // Without DD_SERVICE, a request starts with a default service and ends with its own.
        set(&application, "final");
        client.bind(false).unwrap();
        set(&application, "default");

        request_switching_application(&mut client, &application, true, "end").await;
        // Rebound at the end, not at the start (which would give "default").
        assert_eq!(bound(&client), "end");

        // Without keep, the binding follows at request start only.
        set(&application, "start");
        request_switching_application(&mut client, &application, false, "later").await;
        assert_eq!(bound(&client), "start");
    }

    #[test]
    fn addresses_decode_into_waf_objects() {
        // As the extension writes them: 32-bit containers, a non-UTF-8 string, minimal integers.
        let mut data = vec![0xdf, 0, 0, 0, 8];
        data.extend_from_slice(&[0xa4, b'n', b'u', b'l', b'l', 0xc0]);
        data.extend_from_slice(&[0xa4, b'b', b'o', b'o', b'l', 0xc3]);
        data.extend_from_slice(&[0xa3, b'i', b'n', b't', 0xff]);
        data.extend_from_slice(&[0xa4, b'u', b'i', b'n', b't', 0x01]);
        data.extend_from_slice(&[0xa5, b'f', b'l', b'o', b'a', b't', 0xcb]);
        data.extend_from_slice(&1.5f64.to_bits().to_be_bytes());
        data.extend_from_slice(&[0xa3, b's', b't', b'r', 0xa2, 0xff, b'a']);
        data.extend_from_slice(&[0xa5, b'a', b'r', b'r', b'a', b'y', 0xdd, 0, 0, 0, 2, 0x01]);
        data.extend_from_slice(&[0xdd, 0, 0, 0, 0]);
        data.extend_from_slice(&[0xa3, b'm', b'a', b'p', 0xdf, 0, 0, 0, 1, 0xa1, b'k', 0xc0]);
        let addresses = AppSecAddresses(AppSecBytes(data));

        let map = decode_addresses(&addresses, 1).unwrap();
        assert_eq!(map.len(), 9, "room is left at the end");
        let types: Vec<_> = map
            .iter()
            .take(8)
            .map(|kv| kv.value().object_type())
            .collect();
        assert_eq!(
            types,
            [
                WafObjectType::Null,
                WafObjectType::Bool,
                WafObjectType::Signed,
                WafObjectType::Unsigned,
                WafObjectType::Float,
                WafObjectType::String,
                WafObjectType::Array,
                WafObjectType::Map,
            ]
        );
        let s = map[5]
            .value()
            .as_type::<libddwaf::object::WafString>()
            .unwrap();
        assert_eq!(s.as_bytes(), &[0xff, b'a']);

        assert_eq!(
            decode_addresses(&AppSecAddresses::default(), 0)
                .unwrap()
                .len(),
            0
        );
        assert!(
            decode_addresses(&AppSecAddresses(AppSecBytes(vec![0xdf, 0, 0, 0, 1])), 0).is_err()
        );
    }

    #[test]
    fn oversized_outcome_drops_schemas_then_extra_triggers() {
        let big = "x".repeat(MAX_OUTCOME_SIZE / 2);
        let mut outcome = AppSecOutcome {
            triggers: vec![big.clone(), big.clone()],
            meta: vec![
                ("_dd.appsec.s.req.body".to_owned(), big.clone()),
                (
                    "_dd.appsec.event_rules.version".to_owned(),
                    "1.0".to_owned(),
                ),
            ],
            ..Default::default()
        };
        cap_outcome_size(&mut outcome);
        assert_eq!(outcome.meta.len(), 1);
        assert_eq!(outcome.triggers.len(), 1);

        let mut small = AppSecOutcome {
            triggers: vec!["{}".to_owned(), "{}".to_owned()],
            meta: vec![("_dd.appsec.s.req.body".to_owned(), "[]".to_owned())],
            ..Default::default()
        };
        cap_outcome_size(&mut small);
        assert_eq!(small.triggers.len(), 2);
        assert_eq!(small.meta.len(), 1);
    }

    #[test]
    fn record_is_injected_for_triggers_and_stack_traces() {
        let mut actions = vec![];
        maybe_inject_record_action(&mut actions, true);
        assert_eq!(actions.len(), 1);
        assert_eq!(actions[0].action, "record");

        let mut actions = vec![AppSecAction {
            action: "stack_trace".to_owned(),
            parameters: vec![("stack_id".to_owned(), "1".to_owned())],
        }];
        maybe_inject_record_action(&mut actions, false);
        assert_eq!(actions.len(), 2);

        let mut actions = vec![AppSecAction {
            action: "block".to_owned(),
            parameters: vec![],
        }];
        maybe_inject_record_action(&mut actions, true);
        assert_eq!(actions.len(), 1);
    }
}
