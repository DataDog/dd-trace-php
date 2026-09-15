// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::settings::AgentlessEndpoint;
use super::{store_config, ConfigurationTransition};
use crate::log::{self, Log};
use base64::Engine;
use datadog_ffe::rules_based::{Configuration, UniversalFlagConfig};
use flate2::read::GzDecoder;
use futures_util::FutureExt;
use http::header::{ACCEPT_ENCODING, CONTENT_ENCODING, CONTENT_LENGTH, ETAG, IF_NONE_MATCH};
use http::{Method, Request};
use http_body_util::BodyExt;
use libdd_common::http_common::{self, Body};
use percent_encoding::percent_decode_str;
use std::future::Future;
use std::io::Read;
use std::panic::AssertUnwindSafe;
use std::pin::Pin;
use std::sync::atomic::{AtomicU64, AtomicU8, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};
use std::thread::JoinHandle;
use std::time::Duration;
use tokio_util::sync::CancellationToken;

const MAX_POLL_ATTEMPTS: usize = 3;
const MAX_RESPONSE_BODY_BYTES: usize = 10 << 20;
const CLIENT_LANGUAGE: &str = "php";
const INTERNAL_UNTRACED_HEADER: &str = "DD-Internal-Untraced-Request";
const API_KEY_HEADER: &str = "DD-API-KEY";
const AUTHORIZATION_HEADER: &str = "Authorization";

type FetchFuture<'a> =
    Pin<Box<dyn Future<Output = Result<PollResponse, TransportFailure>> + Send + 'a>>;

trait Transport: Send + Sync {
    fn get<'a>(
        &'a self,
        endpoint: &'a AgentlessEndpoint,
        timeout: Duration,
        etag: Option<&'a str>,
        cancellation: &'a CancellationToken,
    ) -> FetchFuture<'a>;
}

trait ConfigurationSink: Send + Sync {
    fn apply(&self, configuration: Configuration) -> Result<ConfigurationTransition, ()>;
}

struct GlobalConfigurationSink;

impl ConfigurationSink for GlobalConfigurationSink {
    fn apply(&self, configuration: Configuration) -> Result<ConfigurationTransition, ()> {
        Ok(store_config(configuration))
    }
}

#[derive(Debug, Eq, PartialEq)]
enum TransportFailure {
    BuildRequest,
    Cancelled,
    TimedOut,
    Request,
    ResponseBody,
    ResponseTooLarge,
}

struct PollResponse {
    status: u16,
    etag: Option<String>,
    content_encoding: Option<String>,
    body: Vec<u8>,
}

struct HyperTransport {
    client: libdd_common::HttpClient,
}

impl HyperTransport {
    fn new() -> Self {
        Self {
            // This native client is outside PHP HTTP instrumentation, so it
            // cannot recursively trace its own polling requests. Hyper also
            // leaves 3xx responses visible instead of following redirects.
            client: http_common::new_client_periodic(),
        }
    }

    async fn get_inner(
        &self,
        endpoint: &AgentlessEndpoint,
        timeout: Duration,
        etag: Option<&str>,
        cancellation: &CancellationToken,
    ) -> Result<PollResponse, TransportFailure> {
        let request = build_request(endpoint, etag)?;
        let operation = async {
            let response = self
                .client
                .request(request)
                .await
                .map_err(|_| TransportFailure::Request)?;
            let status = response.status().as_u16();
            let etag = header_value(response.headers(), ETAG);
            let content_encoding = header_value(response.headers(), CONTENT_ENCODING);

            // A non-200 body can never contain configuration. Dropping it here
            // also prevents a malicious error response from consuming memory.
            if status != 200 {
                return Ok(PollResponse {
                    status,
                    etag,
                    content_encoding,
                    body: Vec::new(),
                });
            }

            if response
                .headers()
                .get(CONTENT_LENGTH)
                .and_then(|value| value.to_str().ok())
                .and_then(|value| value.parse::<u64>().ok())
                .is_some_and(|length| length > MAX_RESPONSE_BODY_BYTES as u64)
            {
                return Err(TransportFailure::ResponseTooLarge);
            }

            let body = collect_bounded(response.into_body(), MAX_RESPONSE_BODY_BYTES).await?;
            Ok(PollResponse {
                status,
                etag,
                content_encoding,
                body,
            })
        };

        tokio::select! {
            _ = cancellation.cancelled() => Err(TransportFailure::Cancelled),
            result = tokio::time::timeout(timeout, operation) => {
                result.map_err(|_| TransportFailure::TimedOut)?
            }
        }
    }
}

impl Transport for HyperTransport {
    fn get<'a>(
        &'a self,
        endpoint: &'a AgentlessEndpoint,
        timeout: Duration,
        etag: Option<&'a str>,
        cancellation: &'a CancellationToken,
    ) -> FetchFuture<'a> {
        Box::pin(self.get_inner(endpoint, timeout, etag, cancellation))
    }
}

fn build_request(
    endpoint: &AgentlessEndpoint,
    etag: Option<&str>,
) -> Result<Request<Body>, TransportFailure> {
    let (request_url, authorization) = request_url_and_authorization(endpoint.as_str())?;
    let mut builder = Request::builder()
        .method(Method::GET)
        .uri(request_url)
        .header(ACCEPT_ENCODING, "gzip")
        .header("DD-Client-Library-Language", CLIENT_LANGUAGE)
        .header(
            "DD-Client-Library-Version",
            include_str!("../../VERSION").trim(),
        )
        .header(INTERNAL_UNTRACED_HEADER, "1");

    if let Some(etag) = etag {
        builder = builder.header(IF_NONE_MATCH, etag);
    }
    if let Some(api_key) = endpoint.api_key() {
        builder = builder.header(API_KEY_HEADER, api_key);
    }
    if let Some(authorization) = authorization {
        builder = builder.header(AUTHORIZATION_HEADER, authorization);
    }

    builder
        .body(Body::empty())
        .map_err(|_| TransportFailure::BuildRequest)
}

fn request_url_and_authorization(
    endpoint: &str,
) -> Result<(String, Option<String>), TransportFailure> {
    let mut url = url::Url::parse(endpoint).map_err(|_| TransportFailure::BuildRequest)?;
    if url.username().is_empty() {
        return Ok((endpoint.to_owned(), None));
    }

    let mut credentials = percent_decode_str(url.username()).collect::<Vec<_>>();
    credentials.push(b':');
    if let Some(password) = url.password() {
        credentials.extend(percent_decode_str(password));
    }
    let authorization = format!(
        "Basic {}",
        base64::engine::general_purpose::STANDARD.encode(credentials)
    );

    url.set_username("")
        .map_err(|_| TransportFailure::BuildRequest)?;
    url.set_password(None)
        .map_err(|_| TransportFailure::BuildRequest)?;
    Ok((url.to_string(), Some(authorization)))
}

fn header_value(headers: &http::HeaderMap, name: http::header::HeaderName) -> Option<String> {
    headers
        .get(name)
        .and_then(|value| value.to_str().ok())
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(str::to_owned)
}

async fn collect_bounded(
    mut body: hyper::body::Incoming,
    limit: usize,
) -> Result<Vec<u8>, TransportFailure> {
    let mut bytes = Vec::new();
    while let Some(frame) = body.frame().await {
        let frame = frame.map_err(|_| TransportFailure::ResponseBody)?;
        if let Some(data) = frame.data_ref() {
            let remaining = limit.saturating_sub(bytes.len());
            if data.len() > remaining {
                return Err(TransportFailure::ResponseTooLarge);
            }
            bytes.extend_from_slice(data);
        }
    }
    Ok(bytes)
}

fn decode_response_body(
    body: &[u8],
    content_encoding: Option<&str>,
    limit: usize,
) -> Result<Vec<u8>, TransportFailure> {
    if !content_encoding.is_some_and(|value| value.trim().eq_ignore_ascii_case("gzip")) {
        if body.len() > limit {
            return Err(TransportFailure::ResponseTooLarge);
        }
        return Ok(body.to_vec());
    }

    let decoder = GzDecoder::new(body);
    let mut decoded = Vec::new();
    decoder
        .take(limit as u64 + 1)
        .read_to_end(&mut decoded)
        .map_err(|_| TransportFailure::ResponseBody)?;
    if decoded.len() > limit {
        return Err(TransportFailure::ResponseTooLarge);
    }
    Ok(decoded)
}

#[derive(Clone, Copy)]
#[repr(u8)]
enum WarningCategory {
    Authentication = 1 << 0,
    RetryableHttp = 1 << 1,
    UnexpectedHttp = 1 << 2,
    Request = 1 << 3,
    MalformedPayload = 1 << 4,
    ApplyFailure = 1 << 5,
    UnexpectedException = 1 << 6,
}

#[derive(Default)]
struct WarningSlots(AtomicU8);

impl WarningSlots {
    fn take(&self, category: WarningCategory) -> bool {
        self.0.fetch_or(category as u8, Ordering::Relaxed) & category as u8 == 0
    }

    fn reset(&self) {
        self.0.store(0, Ordering::Relaxed);
    }
}

#[derive(Default)]
struct PollState {
    etag: Mutex<Option<String>>,
    warnings: WarningSlots,
    poll_count: AtomicU64,
}

impl PollState {
    fn etag(&self) -> Option<String> {
        lock_unpoisoned(&self.etag).clone()
    }

    fn set_etag(&self, etag: Option<String>) {
        *lock_unpoisoned(&self.etag) = etag;
    }
}

fn lock_unpoisoned<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner)
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum PollOutcome {
    Success,
    Retryable,
    Stop,
    Cancelled,
}

struct Poller<T: Transport, S: ConfigurationSink> {
    endpoint: AgentlessEndpoint,
    poll_interval: Duration,
    request_timeout: Duration,
    transport: T,
    sink: S,
    state: Arc<PollState>,
    retry_delay: fn(Duration, usize, f64) -> Duration,
}

impl<T: Transport, S: ConfigurationSink> Poller<T, S> {
    async fn run(&mut self, cancellation: CancellationToken) {
        loop {
            if cancellation.is_cancelled() {
                return;
            }

            let poll = AssertUnwindSafe(self.poll(&cancellation)).catch_unwind();
            match poll.await {
                Ok(PollOutcome::Cancelled) => return,
                Ok(_) => {}
                Err(_) => self.warn(
                    WarningCategory::UnexpectedException,
                    "Feature Flags agentless polling failed unexpectedly; polling continues",
                ),
            }

            tokio::select! {
                _ = cancellation.cancelled() => return,
                _ = tokio::time::sleep(self.poll_interval) => {}
            }
        }
    }

    async fn poll(&mut self, cancellation: &CancellationToken) -> PollOutcome {
        for attempt in 1..=MAX_POLL_ATTEMPTS {
            let outcome = self.poll_once(cancellation).await;
            if outcome != PollOutcome::Retryable || attempt == MAX_POLL_ATTEMPTS {
                return outcome;
            }

            let delay = (self.retry_delay)(self.poll_interval, attempt, fastrand::f64());
            tokio::select! {
                _ = cancellation.cancelled() => return PollOutcome::Cancelled,
                _ = tokio::time::sleep(delay) => {}
            }
        }
        PollOutcome::Stop
    }

    async fn poll_once(&mut self, cancellation: &CancellationToken) -> PollOutcome {
        if cancellation.is_cancelled() {
            return PollOutcome::Cancelled;
        }

        self.state.poll_count.fetch_add(1, Ordering::Relaxed);
        let etag = self.state.etag();
        match self
            .transport
            .get(
                &self.endpoint,
                self.request_timeout,
                etag.as_deref(),
                cancellation,
            )
            .await
        {
            Ok(response) => self.process_response(response),
            Err(TransportFailure::Cancelled) => PollOutcome::Cancelled,
            Err(TransportFailure::BuildRequest) => {
                self.warn(
                    WarningCategory::Request,
                    "Feature Flags agentless request could not be built",
                );
                PollOutcome::Stop
            }
            Err(TransportFailure::ResponseTooLarge) => {
                self.warn(
                    WarningCategory::MalformedPayload,
                    "Feature Flags agentless response exceeds the 10 MiB limit; keeping the last known configuration",
                );
                PollOutcome::Stop
            }
            Err(_) => {
                self.warn(
                    WarningCategory::Request,
                    "Feature Flags agentless request failed; keeping the last known configuration",
                );
                PollOutcome::Retryable
            }
        }
    }

    fn process_response(&mut self, response: PollResponse) -> PollOutcome {
        match response.status {
            304 => return PollOutcome::Success,
            401 | 403 => {
                self.warn(
                    WarningCategory::Authentication,
                    "Feature Flags agentless endpoint rejected authentication; verify endpoint authentication",
                );
                return PollOutcome::Stop;
            }
            200 => {}
            status if is_retryable_status(status) => {
                self.warn(
                    WarningCategory::RetryableHttp,
                    &format!("Feature Flags agentless endpoint returned retryable HTTP {status}"),
                );
                return PollOutcome::Retryable;
            }
            status => {
                self.warn(
                    WarningCategory::UnexpectedHttp,
                    &format!("Feature Flags agentless endpoint returned unexpected HTTP {status}"),
                );
                return PollOutcome::Stop;
            }
        }

        let body = match decode_response_body(
            &response.body,
            response.content_encoding.as_deref(),
            MAX_RESPONSE_BODY_BYTES,
        ) {
            Ok(body) => body,
            Err(TransportFailure::ResponseTooLarge) => {
                self.warn(
                    WarningCategory::MalformedPayload,
                    "Feature Flags agentless decoded response exceeds the 10 MiB limit; keeping the last known configuration",
                );
                return PollOutcome::Stop;
            }
            Err(_) => {
                self.warn(
                    WarningCategory::MalformedPayload,
                    "Feature Flags agentless endpoint returned an invalid response body",
                );
                return PollOutcome::Stop;
            }
        };

        let configuration = match UniversalFlagConfig::from_json(body) {
            Ok(configuration) => Configuration::from_server_response(configuration),
            Err(_) => {
                self.warn(
                    WarningCategory::MalformedPayload,
                    "Feature Flags agentless endpoint returned malformed configuration",
                );
                return PollOutcome::Stop;
            }
        };

        if self.sink.apply(configuration).is_err() {
            self.warn(
                WarningCategory::ApplyFailure,
                "Feature Flags agentless configuration could not be applied",
            );
            return PollOutcome::Stop;
        }

        // The response has now been parsed and applied. A blank or absent
        // ETag intentionally clears an older value.
        self.state.set_etag(response.etag);
        PollOutcome::Success
    }

    fn warn(&self, category: WarningCategory, message: &str) {
        if self.state.warnings.take(category) {
            log::log(Log::Warn, message);
        }
    }
}

fn is_retryable_status(status: u16) -> bool {
    matches!(status, 408 | 429 | 500..=599)
}

fn retry_delay(poll_interval: Duration, attempt: usize, random: f64) -> Duration {
    let base = if attempt <= 1 {
        (poll_interval / 6).clamp(Duration::from_secs(2), Duration::from_secs(10))
    } else {
        (poll_interval / 3).clamp(Duration::from_secs(5), Duration::from_secs(30))
    };
    base.mul_f64(0.8 + random.clamp(0.0, 1.0) * 0.4)
        .max(Duration::from_secs(1))
}

#[derive(Clone)]
pub(crate) struct AgentlessWorkerConfig {
    endpoint: AgentlessEndpoint,
    poll_interval: Duration,
    request_timeout: Duration,
}

impl AgentlessWorkerConfig {
    pub(crate) fn new(
        endpoint: AgentlessEndpoint,
        poll_interval: Duration,
        request_timeout: Duration,
    ) -> Self {
        Self {
            endpoint,
            poll_interval,
            request_timeout,
        }
    }
}

struct RunningWorker {
    cancellation: CancellationToken,
    thread: JoinHandle<()>,
}

#[derive(Debug, Eq, PartialEq)]
pub(crate) enum WorkerStartError {
    ThreadUnavailable,
}

/// Owns the poll thread and makes its lifecycle explicit around `fork`.
/// PR 3 connects these methods to the tracer lifecycle hooks.
pub(crate) struct AgentlessWorker {
    config: AgentlessWorkerConfig,
    state: Arc<PollState>,
    running: Option<RunningWorker>,
    restart_after_fork: bool,
    permanently_stopped: bool,
}

impl AgentlessWorker {
    pub(crate) fn new(config: AgentlessWorkerConfig) -> Self {
        Self {
            config,
            state: Arc::new(PollState::default()),
            running: None,
            restart_after_fork: false,
            permanently_stopped: false,
        }
    }

    pub(crate) fn start(&mut self) -> Result<bool, WorkerStartError> {
        if self.running.is_some() || self.permanently_stopped {
            return Ok(false);
        }

        let cancellation = CancellationToken::new();
        let thread_cancellation = cancellation.clone();
        let config = self.config.clone();
        let state = Arc::clone(&self.state);
        let log_dispatch = tracing::dispatcher::get_default(Clone::clone);
        let thread = std::thread::Builder::new()
            .name("ddtrace-ffe-agentless".to_owned())
            .spawn(move || {
                let _log_guard = tracing::dispatcher::set_default(&log_dispatch);
                let runtime = match tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                {
                    Ok(runtime) => runtime,
                    Err(_) => {
                        log::log(
                            Log::Warn,
                            "Feature Flags agentless runtime could not be created",
                        );
                        return;
                    }
                };

                runtime.block_on(async move {
                    let mut poller = Poller {
                        endpoint: config.endpoint,
                        poll_interval: config.poll_interval,
                        request_timeout: config.request_timeout,
                        transport: HyperTransport::new(),
                        sink: GlobalConfigurationSink,
                        state,
                        retry_delay,
                    };
                    poller.run(thread_cancellation).await;
                });
            })
            .map_err(|_| WorkerStartError::ThreadUnavailable)?;

        self.running = Some(RunningWorker {
            cancellation,
            thread,
        });
        Ok(true)
    }

    pub(crate) fn shutdown(&mut self) {
        self.permanently_stopped = true;
        self.stop_running();
    }

    pub(crate) fn prepare_for_fork(&mut self) {
        self.restart_after_fork = self.running.is_some() && !self.permanently_stopped;
        self.stop_running();
    }

    pub(crate) fn resume_after_fork_parent(&mut self) -> Result<(), WorkerStartError> {
        self.restart_after_fork()
    }

    pub(crate) fn reset_after_fork_child(&mut self) -> Result<(), WorkerStartError> {
        // `prepare_for_fork` joined the only worker before the process split,
        // so no synchronization primitive can be inherited while held.
        self.state.warnings.reset();
        self.restart_after_fork()
    }

    pub(crate) fn poll_count(&self) -> u64 {
        self.state.poll_count.load(Ordering::Relaxed)
    }

    fn restart_after_fork(&mut self) -> Result<(), WorkerStartError> {
        if self.restart_after_fork && !self.permanently_stopped {
            self.start()?;
            self.restart_after_fork = false;
        }
        Ok(())
    }

    fn stop_running(&mut self) {
        if let Some(running) = self.running.take() {
            running.cancellation.cancel();
            let _ = running.thread.join();
        }
    }
}

impl Drop for AgentlessWorker {
    fn drop(&mut self) {
        self.stop_running();
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::VecDeque;
    use std::io::Write;
    use std::sync::atomic::{AtomicBool, AtomicUsize};

    const EMPTY_CONFIG: &[u8] = br#"{
        "createdAt": "2026-05-22T00:00:00.000Z",
        "format": "SERVER",
        "environment": {"name": "test"},
        "flags": {}
    }"#;

    struct UnusedTransport;

    impl Transport for UnusedTransport {
        fn get<'a>(
            &'a self,
            _endpoint: &'a AgentlessEndpoint,
            _timeout: Duration,
            _etag: Option<&'a str>,
            _cancellation: &'a CancellationToken,
        ) -> FetchFuture<'a> {
            Box::pin(async { unreachable!("transport is not used by response-processing tests") })
        }
    }

    struct TestSink {
        fail: AtomicBool,
    }

    struct QueueTransport {
        responses: Mutex<VecDeque<Result<PollResponse, TransportFailure>>>,
        calls: AtomicUsize,
        in_flight: AtomicUsize,
        max_in_flight: AtomicUsize,
    }

    impl QueueTransport {
        fn new(
            responses: impl IntoIterator<Item = Result<PollResponse, TransportFailure>>,
        ) -> Self {
            Self {
                responses: Mutex::new(responses.into_iter().collect()),
                calls: AtomicUsize::new(0),
                in_flight: AtomicUsize::new(0),
                max_in_flight: AtomicUsize::new(0),
            }
        }
    }

    impl Transport for QueueTransport {
        fn get<'a>(
            &'a self,
            _endpoint: &'a AgentlessEndpoint,
            _timeout: Duration,
            _etag: Option<&'a str>,
            _cancellation: &'a CancellationToken,
        ) -> FetchFuture<'a> {
            Box::pin(async move {
                self.calls.fetch_add(1, Ordering::Relaxed);
                let in_flight = self.in_flight.fetch_add(1, Ordering::Relaxed) + 1;
                self.max_in_flight.fetch_max(in_flight, Ordering::Relaxed);
                tokio::task::yield_now().await;
                let result = lock_unpoisoned(&self.responses)
                    .pop_front()
                    .unwrap_or(Err(TransportFailure::Request));
                self.in_flight.fetch_sub(1, Ordering::Relaxed);
                result
            })
        }
    }

    impl ConfigurationSink for TestSink {
        fn apply(&self, _configuration: Configuration) -> Result<ConfigurationTransition, ()> {
            if self.fail.load(Ordering::Relaxed) {
                Err(())
            } else {
                Ok(ConfigurationTransition::Ready)
            }
        }
    }

    fn test_poller(fail_apply: bool) -> Poller<UnusedTransport, TestSink> {
        Poller {
            endpoint: AgentlessEndpoint::build("http://localhost/custom", "", "", "")
                .expect("test endpoint"),
            poll_interval: Duration::from_secs(30),
            request_timeout: Duration::from_secs(5),
            transport: UnusedTransport,
            sink: TestSink {
                fail: AtomicBool::new(fail_apply),
            },
            state: Arc::new(PollState::default()),
            retry_delay,
        }
    }

    fn no_retry_delay(_: Duration, _: usize, _: f64) -> Duration {
        Duration::ZERO
    }

    fn response(status: u16, etag: Option<&str>, body: &[u8]) -> PollResponse {
        PollResponse {
            status,
            etag: etag.map(str::to_owned),
            content_encoding: None,
            body: body.to_vec(),
        }
    }

    fn gzip(payload: &[u8]) -> Vec<u8> {
        let mut encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::fast());
        encoder.write_all(payload).expect("compress test payload");
        encoder.finish().expect("finish test payload")
    }

    #[test]
    fn retries_only_transport_timeouts_and_selected_statuses() {
        for status in [408, 429, 500, 503, 599] {
            assert!(is_retryable_status(status), "status {status}");
        }
        for status in [200, 204, 301, 400, 401, 403, 404, 499, 600] {
            assert!(!is_retryable_status(status), "status {status}");
        }
    }

    #[test]
    fn retry_delay_uses_the_cross_sdk_clamps_and_jitter() {
        assert_eq!(
            retry_delay(Duration::from_secs(30), 1, 0.0),
            Duration::from_secs(4)
        );
        assert_eq!(
            retry_delay(Duration::from_secs(30), 1, 1.0),
            Duration::from_secs(6)
        );
        assert_eq!(
            retry_delay(Duration::from_secs(30), 2, 0.0),
            Duration::from_secs(8)
        );
        assert_eq!(
            retry_delay(Duration::from_secs(30), 2, 1.0),
            Duration::from_secs(12)
        );
        assert_eq!(
            retry_delay(Duration::from_secs(1), 1, 0.0),
            Duration::from_millis(1_600)
        );
    }

    #[test]
    fn decoded_body_limit_applies_after_gzip() {
        let payload = vec![b'a'; 256];
        let compressed = gzip(&payload);
        assert!(compressed.len() < 64);
        assert_eq!(
            decode_response_body(&compressed, Some(" GZIP "), 64),
            Err(TransportFailure::ResponseTooLarge)
        );
        assert_eq!(
            decode_response_body(&gzip(b"configuration"), Some("gzip"), 64).expect("decode gzip"),
            b"configuration"
        );
    }

    #[test]
    fn etag_advances_only_after_parse_and_apply_succeed() {
        let mut poller = test_poller(true);
        poller.state.set_etag(Some("old".to_owned()));

        assert_eq!(
            poller.process_response(response(200, Some("new"), EMPTY_CONFIG)),
            PollOutcome::Stop
        );
        assert_eq!(poller.state.etag().as_deref(), Some("old"));

        poller.sink.fail.store(false, Ordering::Relaxed);
        assert_eq!(
            poller.process_response(response(200, Some("new"), EMPTY_CONFIG)),
            PollOutcome::Success
        );
        assert_eq!(poller.state.etag().as_deref(), Some("new"));

        assert_eq!(
            poller.process_response(response(200, Some("ignored"), b"not-json")),
            PollOutcome::Stop
        );
        assert_eq!(poller.state.etag().as_deref(), Some("new"));
    }

    #[test]
    fn non_200_responses_are_never_decoded() {
        let mut poller = test_poller(false);
        poller.state.set_etag(Some("current".to_owned()));
        assert_eq!(
            poller.process_response(response(304, Some("ignored"), b"not-json")),
            PollOutcome::Success
        );
        assert_eq!(poller.state.etag().as_deref(), Some("current"));
        assert_eq!(
            poller.process_response(response(401, None, EMPTY_CONFIG)),
            PollOutcome::Stop
        );
        assert_eq!(poller.state.etag().as_deref(), Some("current"));
    }

    #[test]
    fn request_authentication_is_managed_only() {
        let managed =
            AgentlessEndpoint::build("", "datadoghq.com", "", "secret").expect("managed endpoint");
        let custom =
            AgentlessEndpoint::build("https://user:password@localhost/custom", "", "", "secret")
                .expect("custom endpoint");

        let managed_request = build_request(&managed, Some("etag")).expect("managed request");
        assert_eq!(managed_request.headers()[API_KEY_HEADER], "secret");
        assert_eq!(managed_request.headers()[IF_NONE_MATCH], "etag");
        assert_eq!(managed_request.headers()[INTERNAL_UNTRACED_HEADER], "1");

        let custom_request = build_request(&custom, None).expect("custom request");
        assert!(!custom_request.headers().contains_key(API_KEY_HEADER));
        assert_eq!(
            custom_request.headers()[AUTHORIZATION_HEADER],
            "Basic dXNlcjpwYXNzd29yZA=="
        );
        assert_eq!(custom_request.uri(), "https://localhost/custom");
    }

    #[test]
    fn warning_categories_are_suppressed_independently() {
        let warnings = WarningSlots::default();
        assert!(warnings.take(WarningCategory::Request));
        assert!(!warnings.take(WarningCategory::Request));
        assert!(warnings.take(WarningCategory::UnexpectedHttp));
        assert!(!warnings.take(WarningCategory::UnexpectedHttp));
        warnings.reset();
        assert!(warnings.take(WarningCategory::Request));
    }

    #[tokio::test]
    async fn a_poll_retries_three_times_without_overlapping_requests() {
        let transport = QueueTransport::new([
            Ok(response(503, None, b"ignored")),
            Err(TransportFailure::TimedOut),
            Ok(response(200, Some("accepted"), EMPTY_CONFIG)),
        ]);
        let state = Arc::new(PollState::default());
        let mut poller = Poller {
            endpoint: AgentlessEndpoint::build("http://localhost/custom", "", "", "")
                .expect("test endpoint"),
            poll_interval: Duration::from_secs(30),
            request_timeout: Duration::from_secs(5),
            transport,
            sink: TestSink {
                fail: AtomicBool::new(false),
            },
            state: Arc::clone(&state),
            retry_delay: no_retry_delay,
        };

        assert_eq!(
            poller.poll(&CancellationToken::new()).await,
            PollOutcome::Success
        );
        assert_eq!(poller.transport.calls.load(Ordering::Relaxed), 3);
        assert_eq!(poller.transport.max_in_flight.load(Ordering::Relaxed), 1);
        assert_eq!(state.poll_count.load(Ordering::Relaxed), 3);
        assert_eq!(state.etag().as_deref(), Some("accepted"));
    }

    #[tokio::test]
    async fn cancellation_before_a_poll_prevents_the_first_request() {
        let transport = QueueTransport::new([]);
        let mut poller = Poller {
            endpoint: AgentlessEndpoint::build("http://localhost/custom", "", "", "")
                .expect("test endpoint"),
            poll_interval: Duration::from_secs(30),
            request_timeout: Duration::from_secs(5),
            transport,
            sink: TestSink {
                fail: AtomicBool::new(false),
            },
            state: Arc::new(PollState::default()),
            retry_delay: no_retry_delay,
        };
        let cancellation = CancellationToken::new();
        cancellation.cancel();

        assert_eq!(poller.poll(&cancellation).await, PollOutcome::Cancelled);
        assert_eq!(poller.transport.calls.load(Ordering::Relaxed), 0);
    }
}
