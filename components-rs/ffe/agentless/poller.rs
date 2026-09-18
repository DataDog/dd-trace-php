// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::super::settings::AgentlessEndpoint;
use super::transport::{
    decode_response_body, PollResponse, Transport, TransportFailure, MAX_RESPONSE_BODY_BYTES,
};
use crate::ffe::{store_config, ConfigurationTransition};
use crate::log::{self, Log};
use datadog_ffe::rules_based::{Configuration, UniversalFlagConfig};
use futures_util::FutureExt;
use std::panic::AssertUnwindSafe;
use std::sync::atomic::{AtomicU64, AtomicU8, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};
use std::time::Duration;
use tokio_util::sync::CancellationToken;

const MAX_POLL_ATTEMPTS: usize = 3;

pub(super) trait ConfigurationSink: Send + Sync {
    fn apply(&self, configuration: Configuration) -> Result<ConfigurationTransition, ()>;
}

pub(super) struct GlobalConfigurationSink;

impl ConfigurationSink for GlobalConfigurationSink {
    fn apply(&self, configuration: Configuration) -> Result<ConfigurationTransition, ()> {
        Ok(store_config(configuration))
    }
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
pub(super) struct PollState {
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

    pub(super) fn reset_warnings(&self) {
        self.warnings.reset();
    }

    pub(super) fn poll_count(&self) -> u64 {
        self.poll_count.load(Ordering::Relaxed)
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

pub(super) struct Poller<T: Transport, S: ConfigurationSink> {
    endpoint: AgentlessEndpoint,
    poll_interval: Duration,
    request_timeout: Duration,
    transport: T,
    sink: S,
    state: Arc<PollState>,
    retry_delay: fn(Duration, usize, f64) -> Duration,
}

impl<T: Transport, S: ConfigurationSink> Poller<T, S> {
    pub(super) fn new(
        endpoint: AgentlessEndpoint,
        poll_interval: Duration,
        request_timeout: Duration,
        transport: T,
        sink: S,
        state: Arc<PollState>,
    ) -> Self {
        Self {
            endpoint,
            poll_interval,
            request_timeout,
            transport,
            sink,
            state,
            retry_delay,
        }
    }

    pub(super) async fn run(&mut self, cancellation: CancellationToken) {
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

#[cfg(test)]
mod tests {
    use super::*;
    use crate::ffe::agentless::transport::FetchFuture;
    use std::collections::VecDeque;
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
    fn retry_delay_applies_clamps_and_jitter() {
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
