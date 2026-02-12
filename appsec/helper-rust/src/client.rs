use std::{
    cell::Cell,
    collections::{hash_map::Entry, HashMap},
    io,
    mem::MaybeUninit,
    os::fd::{AsFd, AsRawFd},
    sync::{
        atomic::{self, AtomicU64},
        Arc,
    },
    time::{Duration, Instant},
};

use anyhow::{anyhow, Context};
use futures::{future::Shared, SinkExt};
use libddwaf::{object::WafObjectType, RunnableContext};
use log::{debug, error, info, warning as warn};
use protocol::{ClientInitResp, CommandResponse, ConfigFeaturesResp};
use thiserror::Error;
use tokio::net::UnixStream;
use tokio_stream::StreamExt;
use tokio_util::sync::CancellationToken;

use crate::{
    client::protocol::{RequestExecOptions, WafRunType},
    service::{Service, ServiceFixedConfig, ServiceManager},
    telemetry::{
        error_tel_ctx::{
            clear_error_telemetry_context, update_error_telemetry_context,
            with_error_telemetry_handle,
        },
        SidecarReadyFuture, SidecarStatus, SpanMetaGenerator, SpanMetaName, SpanMetricName,
        SpanMetricsGenerator, SpanMetricsSubmitter, TelemetryLogsGenerator,
        TelemetryMetricsGenerator, TelemetrySidecarLogSubmitter, TelemetrySidecarMetricSubmitter,
    },
};

mod attributes;
pub mod log;
mod metrics;
pub mod protocol;

/// Smart pointer that tracks worker count for a service.
/// Increments on creation/clone, decrements on drop.
#[derive(Clone)]
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

pub struct Client {
    pub id: u64,
    service_manager: &'static ServiceManager,
    service: Option<TrackedService>,
    sidecar_settings: Option<protocol::SidecarSettings>,
    metrics_last_registered: Cell<Option<Instant>>,
}

static CLIENT_SERIAL: AtomicU64 = AtomicU64::new(0);
impl Client {
    pub fn new(service_manager: &'static ServiceManager) -> Self {
        Self {
            id: CLIENT_SERIAL.fetch_add(1, atomic::Ordering::Relaxed),
            service_manager,
            service: None,
            sidecar_settings: None,
            metrics_last_registered: Default::default(),
        }
    }

    pub async fn entrypoint(
        self,
        stream: UnixStream,
        sidecar_ready: Shared<SidecarReadyFuture>,
        cancel_token: CancellationToken,
    ) {
        log::with_scoped_client_id(
            self.id,
            self.do_entrypoint(stream, sidecar_ready, cancel_token),
        )
        .await;
    }

    async fn do_entrypoint(
        mut self,
        stream: UnixStream,
        sidecar_ready: Shared<SidecarReadyFuture>,
        cancel_token: CancellationToken,
    ) {
        info!("starting");

        let sidecar_ready = sidecar_ready.await;
        if sidecar_ready != SidecarStatus::Ready {
            info!("Sidecar is not ready, no telemetry will be submitted");
            return;
        }

        let res = do_client_entrypoint(&mut self, stream, cancel_token).await;
        match res {
            Ok(_) => {
                info!("ended normally");
            }
            Err(err) if err.is::<ForcefulDisconnect>() => {
                warn!("ended due to client connectivity issue: {}", err);
            }
            Err(err) => {
                error!("ended with failure: {}", err);
            }
        }
    }

    /// Can't be called before client_init
    pub fn get_service(&self) -> &Service {
        self.service.as_ref().expect("service not initialized")
    }
}

/// Indicates a clean client shutdown - the client properly closed its connection
/// after completing all pending writes. This is NOT an error condition.
#[derive(Debug, Error)]
#[error("Client closed connection cleanly")]
struct CleanShutdown;

/// Indicates the client disconnected unexpectedly - partial data was received
/// before the connection was closed (client crash, kill, network issue).
/// This is a connectivity issue, not a protocol error.
#[derive(Debug, Error)]
#[error("Client disconnected with incomplete data: {0}")]
struct ForcefulDisconnect(io::Error);

async fn do_client_entrypoint(
    client: &mut Client,
    stream: UnixStream,
    cancel_token: CancellationToken,
) -> anyhow::Result<()> {
    with_error_telemetry_handle(do_client_entrypoint_inner(client, stream, cancel_token)).await
}

async fn do_client_entrypoint_inner(
    client: &mut Client,
    stream: UnixStream,
    cancel_token: CancellationToken,
) -> anyhow::Result<()> {
    check_peer_uid_unix(&stream).await?;

    let mut framed = tokio_util::codec::Framed::new(stream, protocol::CommandCodec);

    // first, client_init
    match recv_command(&mut framed, &cancel_token).await {
        Ok(protocol::Command::ClientInit(args)) => {
            let resp = handle_client_init(client, *args);
            match resp {
                Ok(resp) => {
                    send_command_resp(&mut framed, resp).await?;
                }
                Err(err) => {
                    let cir = ClientInitResp {
                        version: protocol::VERSION_FOR_PROTO,
                        status: "fail".to_string(),
                        errors: vec![err.to_string()],
                        ..Default::default()
                    };
                    send_command_resp(&mut framed, CommandResponse::ClientInit(cir)).await?;
                    return Err(err);
                }
            }
        }
        Ok(cmd) => {
            return Err(anyhow!("expected client_init, got {:?}", cmd));
        }
        Err(e) if e.is::<CleanShutdown>() => {
            info!("client closed connection before sending first message");
            return Ok(());
        }
        Err(e) if e.is::<ForcefulDisconnect>() => {
            warn!("client forcefully disconnected before sending first message");
            return Err(e);
        }
        Err(e) => {
            return Err(e);
        }
    };

    // then the request loop
    loop {
        match do_request_loop_iter(client, &mut framed, &cancel_token).await {
            Ok(_) => {
                debug!("request done; waiting for new one");
            }
            Err(err) if err.is::<CleanShutdown>() => {
                return Ok(());
            }
            Err(err) if err.is::<ForcefulDisconnect>() => {
                info!("client disconnected unexpectedly");
                return Err(err);
            }
            Err(err) => {
                error!("error in request loop: {}", err);
                return Err(err);
            }
        }
    }
}

fn handle_client_init(
    client: &mut Client,
    args: protocol::ClientInitArgs,
) -> anyhow::Result<protocol::CommandResponse<'_>> {
    let telemetry_settings = args.telemetry_settings.clone();
    let sd = ServiceFixedConfig::new(
        args.appsec_enabled == Some(true),
        args.waf_config.clone(),
        args.remote_config.clone(),
        args.telemetry_settings,
    );

    client.sidecar_settings = Some(args.sidecar_settings.clone());

    update_error_telemetry_context(args.sidecar_settings.clone(), telemetry_settings.clone());

    let last_registration_time = &client.metrics_last_registered;
    let mut tel_metric_submitter = TelemetrySidecarMetricSubmitter::create(
        &args.sidecar_settings,
        &telemetry_settings,
        last_registration_time,
    );

    let service = client
        .service_manager
        .get_service(&sd, tel_metric_submitter.as_mut());

    let mut cir = ClientInitResp {
        version: protocol::VERSION_FOR_PROTO,
        ..Default::default()
    };

    match service {
        Ok(service) => {
            if let Some(diag) = service.take_pending_init_diagnostics_legacy() {
                diag.generate_span_metrics(&mut cir)
            }

            cir.meta.insert(
                crate::telemetry::WAF_VERSION.0.to_string(),
                Service::waf_version().to_string(),
            );
            cir.helper_runtime = Some("rust".to_string());

            client.service = Some(TrackedService::new(service));
            cir.status = "ok".to_string();
            Ok(CommandResponse::ClientInit(cir))
        }
        Err(err) => {
            error!("client init handling error: {:?}", err);
            Err(err).context("client init handling error")
        }
    }
}

fn handle_config_sync(client: &mut Client, args: protocol::ConfigSyncArgs) {
    let Some(ref service) = client.service else {
        error!("ConfigSync received before client_init");
        return;
    };

    let cur_disc = service.fixed_config();
    let telemetry_settings = args.telemetry_settings.clone();
    let new_disc = cur_disc.new_from_config_sync(args);

    let new_disc = match new_disc {
        None => {
            debug!(
                "Settings did not change after config_sync, still {:?}",
                cur_disc.config_sync_settings()
            );
            return;
        }
        Some(new_disc) => {
            debug!(
                "Settings changed after config_sync, {:?} -> {:?}",
                cur_disc.config_sync_settings(),
                new_disc.config_sync_settings()
            );
            new_disc
        }
    };

    if let Some(ref sidecar_settings) = client.sidecar_settings {
        update_error_telemetry_context(sidecar_settings.clone(), telemetry_settings.clone());
    } else {
        clear_error_telemetry_context();
    }

    let mut tel_metric_submitter = match client.sidecar_settings {
        Some(ref sidecar_settings) => TelemetrySidecarMetricSubmitter::create(
            sidecar_settings,
            &telemetry_settings,
            &client.metrics_last_registered,
        ),
        None => {
            // this should have been set in client_init
            error!("Cannot submit telemetry metrics: sidecar_settings unexpectadly not set");
            TelemetrySidecarMetricSubmitter::noop()
        }
    };

    match client
        .service_manager
        .get_service(&new_disc, &mut *tel_metric_submitter)
    {
        Ok(new_service) => {
            client.service = Some(TrackedService::new(new_service));
        }
        Err(e) => {
            error!("Failed to get service with new RC path: {}; will continue running with old service!", e);
        }
    }
}

async fn do_request_loop_iter(
    client: &mut Client,
    framed: &mut tokio_util::codec::Framed<UnixStream, protocol::CommandCodec>,
    cancel_token: &CancellationToken,
) -> anyhow::Result<()> {
    // wait for any number of config_syncs, followed by request_init
    let mut req_ctx = match recv_command(framed, cancel_token).await? {
        protocol::Command::RequestInit(req) => {
            let service = client.get_service();
            let config_snapshot = service.config_snapshot();

            // if ASM is disabled, send ConfigFeatures(false) and we're done
            if !config_snapshot.asm_enabled {
                debug!("ASM disabled, sending config_features(enabled=false) to request_init");
                let resp = protocol::CommandResponse::ConfigFeatures(ConfigFeaturesResp {
                    enabled: false,
                });
                send_command_resp(framed, resp).await?;
                return Ok(());
            }

            let mut req_ctx = ReqContext::new(service, config_snapshot.clone());
            let result = req_ctx.run_waf(req.data, &protocol::RequestExecOptions::regular())?;

            let resp = protocol::CommandResponse::RequestInit(protocol::RequestInitResp {
                triggers: &result.triggers,
                actions: &result.actions,
                force_keep: req_ctx.should_force_keep(service, result.waf_keep),
                settings: req_ctx.settings(),
            });
            send_command_resp(framed, resp).await?;
            req_ctx
        }

        protocol::Command::ConfigSync(args) => {
            handle_config_sync(client, *args);

            let service = client.get_service();
            let enabled = service.config_snapshot().asm_enabled;
            let resp = if enabled {
                protocol::CommandResponse::ConfigFeatures(ConfigFeaturesResp { enabled: true })
            } else {
                protocol::CommandResponse::ConfigSync
            };
            send_command_resp(framed, resp).await?;

            submit_service_telemetry(client, service);

            return Ok(());
        }

        command => {
            anyhow::bail!("unexpected command {:?}", command);
        }
    };

    loop {
        match recv_command(framed, cancel_token).await? {
            protocol::Command::RequestExec(req) => {
                let req_options = if req.options.subctx_id.is_some() {
                    &req.options
                } else if has_server_request_address(&req.data) {
                    // allow overriding of server.request.* variables in request_exec
                    debug!(
                        "Running WAF on the main WAF context because \
                            server.request.* variables are present: {:?}",
                        req.data
                    );
                    &RequestExecOptions::regular()
                } else {
                    // by default, run on a transient subcontext
                    &RequestExecOptions {
                        subctx_id: Some("request_exec_transient".into()),
                        subctx_last_call: true,
                        ..req.options
                    }
                };

                let result = req_ctx.run_waf(req.data, req_options)?;

                let resp = protocol::CommandResponse::RequestExec(protocol::RequestExecResp {
                    triggers: result.triggers,
                    actions: result.actions,
                    force_keep: req_ctx.should_force_keep(client.get_service(), result.waf_keep),
                    settings: HashMap::default(),
                });
                send_command_resp(framed, resp).await?;
                continue;
            }
            protocol::Command::RequestShutdown(req) => {
                let data = if client
                    .get_service()
                    .should_extract_schema(req.api_sec_samp_key)
                {
                    use libddwaf::waf_map;
                    let context_processor = waf_map! {("extract-schema", true)};

                    let old_len = req.data.len() as usize;
                    let mut new_data = libddwaf::object::WafMap::new((old_len + 1) as u16);
                    for (i, entry) in req.data.into_iter().enumerate() {
                        new_data[i] = entry;
                    }
                    new_data[old_len] = ("waf.context.processor", context_processor).into();
                    new_data
                } else {
                    req.data
                };

                let result = req_ctx.run_waf(data, &protocol::RequestExecOptions::regular())?;

                // span metrics / meta
                let mut span_submitter = metrics::CollectingMetricsSubmitter::default();
                req_ctx.generate_span_metrics(&mut span_submitter);
                req_ctx.generate_meta(&mut span_submitter);

                let service = client.get_service();
                let force_keep = req_ctx.should_force_keep(service, result.waf_keep);

                let resp =
                    protocol::CommandResponse::RequestShutdown(protocol::RequestShutdownResp {
                        triggers: result.triggers,
                        actions: result.actions,
                        force_keep,
                        settings: HashMap::default(),
                        meta: span_submitter.take_meta(),
                        metrics: span_submitter.take_metrics(),
                    });
                send_command_resp(framed, resp).await?;

                submit_context_telemetry_metrics(client, &mut req_ctx, req.input_truncated);
                submit_service_telemetry(client, service);

                break;
            }
            command => {
                anyhow::bail!("unexpected command {:?}", command);
            }
        }
    }

    Ok(())
}

fn submit_service_telemetry(client: &Client, service: &Service) {
    if let (Some(sidecar_settings), telemetry_settings) =
        (&client.sidecar_settings, &service.telemetry_settings())
    {
        debug!("Submitting service telemetry to sidecar");
        let mut submitter =
            TelemetrySidecarLogSubmitter::create(sidecar_settings, telemetry_settings);
        service.generate_telemetry_logs(&mut *submitter);

        let mut submitter = TelemetrySidecarMetricSubmitter::create(
            sidecar_settings,
            telemetry_settings,
            &client.metrics_last_registered,
        );
        service.generate_telemetry_metrics(&mut *submitter);
    } else {
        debug!(
            "Cannot submit service telemetry: sidecar_settings={:?}, telemetry_settings={:?}",
            client.sidecar_settings,
            service.telemetry_settings()
        );
    }
}

fn submit_context_telemetry_metrics(
    client: &Client,
    req_ctx: &mut ReqContext,
    input_truncated: bool,
) {
    let Some(ref sidecar_settings) = client.sidecar_settings else {
        warn!("Cannot submit context telemetry metrics: sidecar_settings not set");
        return;
    };
    let service = client.get_service();
    let telemetry_settings = service.telemetry_settings();

    let mut tel_metric_submitter = TelemetrySidecarMetricSubmitter::create(
        sidecar_settings,
        telemetry_settings,
        &client.metrics_last_registered,
    );

    let waf_metrics = req_ctx.take_waf_metrics(input_truncated);
    waf_metrics.generate_telemetry_metrics(&mut *tel_metric_submitter);
}

struct WafRunResult {
    triggers: Vec<String>,
    actions: Vec<protocol::ActionInstance>,
    waf_keep: bool,
}

struct ReqContext {
    waf_ctx: libddwaf::Context,
    waf_subctxs: HashMap<String, libddwaf::Subcontext>,
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
            waf_subctxs: HashMap::new(),
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

    fn run_waf(
        &mut self,
        data: libddwaf::object::WafMap,
        options: &protocol::RequestExecOptions,
    ) -> anyhow::Result<WafRunResult> {
        debug!("Running WAF with: {:?}, options: {:?}", data, options);

        let waf_timeout = self.waf_timeout;
        let mut ctx = self.get_waf_runnable(options)?;
        let maybe_res = tokio::task::block_in_place(|| ctx.run(data, waf_timeout));
        drop(ctx);
        let res = match maybe_res {
            Ok(res) => res,
            Err(err) => {
                self.waf_metrics.record_non_rasp_error_eval();
                anyhow::bail!("WAF evaluation error: {:?}", err);
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
            WafRunType::RaspRule(rule_type) => {
                self.waf_metrics.record_rasp_eval(rule_type, &run_output);
            }
        }

        Ok(WafRunResult {
            triggers,
            actions,
            waf_keep,
        })
    }

    /// Depending on the options, return either the context or a (possibly new)
    /// subcontext. The subcontext may be dropped after the return is dropped,
    /// depending on the options.subctx_last_call flag.
    fn get_waf_runnable(
        &mut self,
        options: &protocol::RequestExecOptions,
    ) -> anyhow::Result<impl RunnableContext + '_> {
        enum RunnableCtx<'a> {
            Borrowed(&'a mut dyn libddwaf::RunnableContext),
            Owned(libddwaf::Subcontext),
        }
        impl<'a> libddwaf::RunnableContext for RunnableCtx<'a> {
            fn run(
                &mut self,
                data: libddwaf::object::WafMap,
                timeout: Duration,
            ) -> Result<libddwaf::RunResult, libddwaf::RunError> {
                match self {
                    RunnableCtx::Borrowed(ctx) => ctx.run(data, timeout),
                    RunnableCtx::Owned(ctx) => ctx.run(data, timeout),
                }
            }
        }
        match options.subctx_id.as_ref() {
            None => Ok(RunnableCtx::Borrowed(&mut self.waf_ctx)),
            Some(subctx_id) => {
                if options.subctx_last_call {
                    let subctx = self
                        .waf_subctxs
                        .remove(subctx_id)
                        .or_else(|| self.waf_ctx.new_subcontext().ok()) // error should not happen
                        .ok_or(anyhow!("Failed to create subcontext"))?;
                    Ok(RunnableCtx::Owned(subctx))
                } else {
                    let waf_ctx = &mut self.waf_ctx;
                    let entry = self.waf_subctxs.entry(subctx_id.clone());
                    match entry {
                        Entry::Occupied(entry) => Ok(RunnableCtx::Borrowed(entry.into_mut())),
                        Entry::Vacant(entry) => {
                            let subctx = entry.insert(
                                waf_ctx
                                    .new_subcontext()
                                    .map_err(|e| anyhow!("Failed to create subcontext: {}", e))?,
                            );
                            Ok(RunnableCtx::Borrowed(subctx))
                        }
                    }
                }
            }
        }
    }

    fn should_force_keep(&mut self, service: &Service, waf_keep: bool) -> bool {
        // cache limiter result (called once per request)
        let limiter_allows = match self.limiter_result {
            Some(result) => result,
            None => {
                let result = service.should_force_keep();
                self.limiter_result = Some(result);
                result
            }
        };

        limiter_allows && waf_keep
    }

    fn settings(&self) -> HashMap<&'static str, String> {
        HashMap::from([(
            "auto_user_instrum",
            self.config_snapshot.auto_user_instrum.as_str().to_string(),
        )])
    }

    pub fn take_waf_metrics(&mut self, input_truncated: bool) -> metrics::WafMetrics {
        self.waf_metrics.set_input_truncated(input_truncated);
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
        // (WAF_VERSION and EVENT_RULES_ERRORS are sent only on client_init)
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

// convert ddwaf map {<action name>: {<param>: <value>}} to Vec<ActionInstance>,
// with some massaging to make inject "record" action.
fn convert_actions(
    actions: &libddwaf::object::WafMap,
    has_triggers: bool,
) -> anyhow::Result<Vec<protocol::ActionInstance>> {
    let conv_actions = actions
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
                .ok_or(anyhow!("Action parameter map not a map"))?
                .iter()
                .try_fold(HashMap::new(), |mut acc, kv| -> anyhow::Result<_> {
                    let key = kv.key_str().map_err(|e| anyhow!(e.to_string()))?;
                    let value = kv.value();
                    let value_str: String = match value.object_type()  {
                        WafObjectType::String => {
                            value.as_type::<libddwaf::object::WafString>()
                            .expect("We just checked it was a string")
                            .as_str()
                            .with_context(|| "Action parameter value is not a UTF-8 String")?
                            .into()
                                                }
                        WafObjectType::Unsigned =>
                            value.as_type::<libddwaf::object::WafUnsigned>()
                            .expect("We just checked it was an unsigned")
                            .value().to_string(),
                        _ => {
                            anyhow::bail!("Action parameter value is not a string or unsigned: got {:?}. Full actions: {:?}", kv.value(), actions)
                        }
                    };
                    acc.insert(key.to_owned(), value_str);
                    Ok(acc)
                })?;
            acc.push(protocol::ActionInstance { action, parameters });
            Ok(acc)
        });

    match conv_actions {
        Ok(mut conv_actions) => {
            maybe_inject_record_action(&mut conv_actions, has_triggers);
            Ok(conv_actions)
        }
        Err(e) => Err(e),
    }
}

/// Injects a "record" action if there are triggers but no actions or
/// if there is a stack_trace action with no block/redirect/record action.
/// The extension only saves triggers if there is a record action.
fn maybe_inject_record_action(actions: &mut Vec<protocol::ActionInstance>, has_triggers: bool) {
    if actions.is_empty() && has_triggers {
        actions.push(protocol::ActionInstance {
            action: "record",
            parameters: HashMap::default(),
        });
    }

    let mut event_action = false;
    let mut stack_trace = false;

    for action in &*actions {
        match action.action {
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
        actions.push(protocol::ActionInstance {
            action: "record",
            parameters: HashMap::default(),
        });
    }
}

fn has_server_request_address(data: &libddwaf::object::WafMap) -> bool {
    data.iter().any(|kv| {
        kv.key_str()
            .map(|k| k.starts_with("server.request."))
            .unwrap_or(false)
    })
}

async fn recv_command(
    framed: &mut tokio_util::codec::Framed<UnixStream, protocol::CommandCodec>,
    cancel_token: &CancellationToken,
) -> anyhow::Result<protocol::Command> {
    debug!("Waiting for command");

    tokio::select! {
        maybe_msg = framed.next() => {
            match maybe_msg {
                Some(Ok(msg)) => {
                    debug!("Received command: {:?}", msg);
                    Ok(msg)
                }
                Some(Err(err)) => {
                    if is_incomplete_stream_error(&err) {
                        Err(ForcefulDisconnect(err).into())
                    } else {
                        // Protocol error: invalid header marker, bad msgpack, unknown command
                        error!("Protocol error receiving command: {}", err);
                        let _ = framed.send(CommandResponse::ProtocolError).await;
                        Err(err.into())
                    }
                }
                None => {
                    // Stream ended cleanly - client closed connection properly
                    Err(CleanShutdown.into())
                }
            }
        }

        _ = cancel_token.cancelled() => {
            info!("Cancellation during client recv");
            Err(CleanShutdown.into())
        }
    }
}

fn is_incomplete_stream_error(err: &io::Error) -> bool {
    // tokio_util's FramedRead returns this specific error when EOF is reached
    // with bytes still in the decode buffer
    err.kind() == io::ErrorKind::Other && err.to_string().contains("bytes remaining on stream")
}

async fn send_command_resp(
    framed: &mut tokio_util::codec::Framed<UnixStream, protocol::CommandCodec>,
    cmd: protocol::CommandResponse<'_>,
) -> anyhow::Result<()> {
    debug!("Sending command: {:?}", cmd);
    match framed.send(cmd).await {
        Ok(_) => Ok(()),
        Err(err) => {
            error!("Error sending command: {}", err);
            Err(err)?
        }
    }
}

async fn check_peer_uid_unix(stream: &UnixStream) -> anyhow::Result<()> {
    let our_euid = unsafe { libc::geteuid() };
    let peer_uid = get_peer_uid_unix(stream).await?;
    if peer_uid == our_euid || peer_uid == 0 {
        debug!(
            "Peer uid check passed: peer_uid={}, our_euid={}",
            peer_uid, our_euid
        );
        Ok(())
    } else {
        Err(anyhow!(
            "Expect peer uid {} (or root), got {}",
            our_euid,
            peer_uid
        ))
    }
}

#[repr(C)]
struct Ucred {
    #[cfg(target_os = "macos")]
    ucred: libc::xucred,

    #[cfg(not(target_os = "macos"))]
    ucred: libc::ucred,
}
impl Ucred {
    #[cfg(target_os = "macos")]
    const LEVEL: libc::c_int = libc::SOL_LOCAL;
    #[cfg(target_os = "macos")]
    const PEERCRED: libc::c_int = libc::LOCAL_PEERCRED;

    #[cfg(not(target_os = "macos"))]
    const LEVEL: libc::c_int = libc::SOL_SOCKET; // protocol independent
    #[cfg(not(target_os = "macos"))]
    const PEERCRED: libc::c_int = libc::SO_PEERCRED;

    fn uid(&self) -> u32 {
        #[cfg(target_os = "macos")]
        return self.ucred.cr_uid;

        #[cfg(not(target_os = "macos"))]
        return self.ucred.uid;
    }
}

async fn get_peer_uid_unix(stream: &UnixStream) -> anyhow::Result<u32> {
    let fd = stream.as_fd();

    let mut cred: MaybeUninit<Ucred> = MaybeUninit::uninit();

    let mut cred_len = std::mem::size_of_val(&cred) as u32;

    let res = unsafe {
        libc::getsockopt(
            fd.as_raw_fd(),
            Ucred::LEVEL,
            Ucred::PEERCRED, // SO_PEERCRED
            &mut cred as *mut _ as *mut libc::c_void,
            &mut cred_len,
        )
    };

    if res == -1 {
        return Err(io::Error::last_os_error())
            .with_context(|| "Call to getsockopt for PEERCRED failed");
    }

    let cred = unsafe { cred.assume_init() };
    let required_len = std::mem::size_of_val(&cred);
    if (cred_len as usize) < required_len {
        anyhow::bail!(
            "Result of getsockopt/PEERCRED: output too small ({} < {})",
            cred_len,
            required_len
        );
    }

    Ok(cred.uid())
}

impl SpanMetricsSubmitter for ClientInitResp {
    fn submit_metric(&mut self, key: SpanMetricName, value: f64) {
        self.metrics.insert(key.0.into(), value);
    }
    fn submit_meta(&mut self, key: SpanMetaName, value: String) {
        self.meta.insert(key.0.into(), value);
    }
    fn submit_meta_dyn_key(&mut self, key: String, value: String) {
        self.meta.insert(key, value);
    }
    fn submit_metric_dyn_key(&mut self, key: String, value: f64) {
        self.metrics.insert(key, value);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use rmp_serde::Serializer;
    use std::path::PathBuf;
    use tokio::io::AsyncWriteExt;
    use tokio::net::UnixStream;

    fn serialize_message<T: serde::Serialize>(command: &T) -> Vec<u8> {
        let mut buf = Vec::new();
        let mut serializer = Serializer::new(&mut buf);
        command.serialize(&mut serializer).unwrap();

        let size = buf.len() as u32;
        let mut full_message = Vec::new();
        full_message.extend_from_slice(b"dds\0");
        full_message.extend_from_slice(&size.to_le_bytes());
        full_message.extend_from_slice(&buf);
        full_message
    }

    fn make_client_init_message() -> Vec<u8> {
        let client_init_args = (
            12345u32,
            "1.0.0",
            "8.0",
            Some(true),
            (
                Some("/path/to/rules"),
                1000u64,
                10u32,
                Option::<&str>::None,
                Some(".*"),
                (true, 0.5f64),
            ),
            (true, PathBuf::from("/dev/shm/remote")),
            ("my-service", "production"),
            ("session-123", "runtime-456"),
        );
        serialize_message(&("client_init", client_init_args))
    }

    /// Tests that demonstrate how Framed<UnixStream, CommandCodec> behaves
    /// with various shutdown scenarios.
    mod framed_behavior {
        use super::*;

        /// When the client cleanly closes the connection (after writing all data),
        /// framed.next() returns None.
        #[tokio::test]
        async fn test_clean_shutdown_returns_none() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            client.shutdown().await.unwrap();

            let result = framed.next().await;
            assert!(
                result.is_none(),
                "Clean shutdown should return None, got {:?}",
                result
            );
        }

        /// When the client sends a complete message then closes cleanly,
        /// we get the message first, then None on the next read.
        #[tokio::test]
        async fn test_message_then_clean_shutdown() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            let msg = make_client_init_message();
            client.write_all(&msg).await.unwrap();
            client.shutdown().await.unwrap();

            let first = framed.next().await;
            assert!(
                matches!(first, Some(Ok(protocol::Command::ClientInit(_)))),
                "Should receive client_init message, got {:?}",
                first
            );

            let second = framed.next().await;
            assert!(
                second.is_none(),
                "After message, clean shutdown should return None, got {:?}",
                second
            );
        }

        /// When the client drops without shutdown (simulating crash/kill),
        /// the behavior depends on whether there's partial data in the buffer.
        /// With no partial data, it returns None (same as clean shutdown).
        #[tokio::test]
        async fn test_drop_without_shutdown_no_partial_data() {
            let (server, client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            drop(client);

            let result = framed.next().await;
            assert!(
                result.is_none(),
                "Drop without partial data returns None (indistinguishable from clean shutdown), got {:?}",
                result
            );
        }

        /// When the client sends partial data (incomplete header) then drops,
        /// Framed returns an io::Error with ErrorKind::Other and message
        /// "bytes remaining on stream". This is tokio_util's way of indicating
        /// the stream closed with incomplete data in the buffer.
        #[tokio::test]
        async fn test_incomplete_header_then_drop() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            client.write_all(b"dds").await.unwrap();
            drop(client);

            let result = framed.next().await;
            match result {
                Some(Err(e)) => {
                    assert_eq!(e.kind(), io::ErrorKind::Other);
                    let msg = e.to_string();
                    assert!(
                        msg.contains("bytes remaining on stream"),
                        "Expected 'bytes remaining on stream' error, got: {}",
                        msg
                    );
                }
                other => panic!("Expected Some(Err(...)), got {:?}", other),
            }
        }

        /// When the client sends a valid header but incomplete body then drops,
        /// Framed returns "bytes remaining on stream" error.
        #[tokio::test]
        async fn test_incomplete_body_then_drop() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            let mut partial = Vec::new();
            partial.extend_from_slice(b"dds\0");
            partial.extend_from_slice(&100u32.to_le_bytes());
            partial.extend_from_slice(b"partial body");

            client.write_all(&partial).await.unwrap();
            drop(client);

            let result = framed.next().await;
            match result {
                Some(Err(e)) => {
                    assert_eq!(e.kind(), io::ErrorKind::Other);
                    let msg = e.to_string();
                    assert!(
                        msg.contains("bytes remaining on stream"),
                        "Expected 'bytes remaining on stream' error, got: {}",
                        msg
                    );
                }
                other => panic!("Expected Some(Err(...)), got {:?}", other),
            }
        }

        /// When the client sends an invalid header marker, we get InvalidData error.
        #[tokio::test]
        async fn test_invalid_header_marker() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            let mut invalid = Vec::new();
            invalid.extend_from_slice(b"bad\0");
            invalid.extend_from_slice(&10u32.to_le_bytes());
            invalid.extend_from_slice(b"0123456789");

            client.write_all(&invalid).await.unwrap();
            client.shutdown().await.unwrap();

            let result = framed.next().await;
            match result {
                Some(Err(e)) => {
                    assert_eq!(
                        e.kind(),
                        io::ErrorKind::InvalidData,
                        "Invalid marker should give InvalidData, got {:?}",
                        e.kind()
                    );
                }
                other => panic!("Expected Some(Err(InvalidData)), got {:?}", other),
            }
        }

        /// When the client sends a valid header but invalid msgpack body,
        /// we get InvalidData error from deserialization.
        #[tokio::test]
        async fn test_invalid_msgpack_body() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            let mut invalid = Vec::new();
            invalid.extend_from_slice(b"dds\0");
            invalid.extend_from_slice(&10u32.to_le_bytes());
            invalid.extend_from_slice(b"not msgpack");

            client.write_all(&invalid).await.unwrap();
            client.shutdown().await.unwrap();

            let result = framed.next().await;
            match result {
                Some(Err(e)) => {
                    assert_eq!(
                        e.kind(),
                        io::ErrorKind::InvalidData,
                        "Invalid msgpack should give InvalidData, got {:?}",
                        e.kind()
                    );
                }
                other => panic!("Expected Some(Err(InvalidData)), got {:?}", other),
            }
        }

        /// When the client sends an unknown command name, we get InvalidData error.
        #[tokio::test]
        async fn test_unknown_command_name() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);

            let msg = serialize_message(&("unknown_command", ()));
            client.write_all(&msg).await.unwrap();
            client.shutdown().await.unwrap();

            let result = framed.next().await;
            match result {
                Some(Err(e)) => {
                    assert_eq!(
                        e.kind(),
                        io::ErrorKind::InvalidData,
                        "Unknown command should give InvalidData, got {:?}",
                        e.kind()
                    );
                }
                other => panic!("Expected Some(Err(InvalidData)), got {:?}", other),
            }
        }
    }

    /// Tests for recv_command error classification
    mod recv_command_errors {
        use super::*;

        /// Clean shutdown should return CleanShutdown error
        #[tokio::test]
        async fn test_clean_shutdown_returns_clean_shutdown_error() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);
            let cancel_token = CancellationToken::new();

            client.shutdown().await.unwrap();

            let result = recv_command(&mut framed, &cancel_token).await;
            assert!(result.is_err());
            let err = result.unwrap_err();
            assert!(
                err.is::<CleanShutdown>(),
                "Clean shutdown should return CleanShutdown, got: {:?}",
                err
            );
        }

        /// Incomplete data should return ForcefulDisconnect error
        #[tokio::test]
        async fn test_incomplete_data_returns_forceful_disconnect() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);
            let cancel_token = CancellationToken::new();

            client.write_all(b"dds").await.unwrap();
            drop(client);

            let result = recv_command(&mut framed, &cancel_token).await;
            assert!(result.is_err());
            let err = result.unwrap_err();
            assert!(
                err.is::<ForcefulDisconnect>(),
                "Incomplete data should return ForcefulDisconnect, got: {:?}",
                err
            );
        }

        /// Invalid protocol (bad header) should NOT return ForcefulDisconnect
        #[tokio::test]
        async fn test_invalid_header_returns_io_error() {
            let (server, mut client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);
            let cancel_token = CancellationToken::new();

            let mut invalid = Vec::new();
            invalid.extend_from_slice(b"bad\0");
            invalid.extend_from_slice(&10u32.to_le_bytes());
            invalid.extend_from_slice(b"0123456789");
            client.write_all(&invalid).await.unwrap();
            client.shutdown().await.unwrap();

            let result = recv_command(&mut framed, &cancel_token).await;
            assert!(result.is_err());
            let err = result.unwrap_err();
            assert!(
                err.downcast_ref::<io::Error>().is_some(),
                "Should contain io::Error in chain, got: {:?}",
                err
            );
        }

        /// Cancellation should return CleanShutdown (treated same as clean close)
        #[tokio::test]
        async fn test_cancellation_returns_clean_shutdown() {
            let (server, _client) = UnixStream::pair().unwrap();
            let mut framed = tokio_util::codec::Framed::new(server, protocol::CommandCodec);
            let cancel_token = CancellationToken::new();

            cancel_token.cancel();

            let result = recv_command(&mut framed, &cancel_token).await;
            assert!(result.is_err());
            let err = result.unwrap_err();
            assert!(
                err.is::<CleanShutdown>(),
                "Cancellation should return CleanShutdown, got: {:?}",
                err
            );
        }
    }
}
