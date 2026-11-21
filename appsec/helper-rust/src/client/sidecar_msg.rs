//! The sidecar's side of the clients: one per connection with AppSec config, driven by the
//! sidecar's callbacks for that connection.

use tokio::sync::{mpsc, oneshot};
use tokio::time::{timeout, Duration};

use crate::client::log::{debug, info, warning};
use crate::error;

use arc_swap::ArcSwapOption;
use datadog_sidecar::appsec::protocol::{AppSecRequest, AppSecSessionConfig, AppSecVerdict};
use datadog_sidecar::appsec::{AppSecApplication, AppSecConnection};
use datadog_sidecar::service::ConnectionSessionHandle;
use std::sync::Arc;
use thiserror::Error;

pub fn start_accepting_messages(new_client: NewClientFn) {
    NEW_CLIENT.store(Some(Arc::new(new_client)));
}

pub fn clear_inherited_state() {
    // The inherited client factory must not wake the parent's Tokio reactor.
    std::mem::forget(NEW_CLIENT.swap(None));
}

pub fn stop_accepting_messages() {
    NEW_CLIENT.store(None);
}

/// The connection's session has AppSec config: start its client, unless one with the same
/// config runs already.
pub fn on_session_config(connection: &mut AppSecConnection, config: &AppSecSessionConfig) {
    if let Some(handle) = client_handle(connection) {
        if handle.config == *config {
            return;
        }
        debug!(
            "AppSec config of session {} changed; replacing its client",
            session_id(connection)
        );
    }
    let application = Arc::new(ArcSwapOption::empty());
    let Some(sender) = spawn_client(connection, config, &application) else {
        connection.client = None;
        return;
    };
    connection.client = Some(Box::new(ClientHandle {
        sender,
        config: config.clone(),
        application,
    }));
}

/// The client reads the application when a request starts or ends, so storing it is enough.
pub fn on_application(connection: &mut AppSecConnection, application: &AppSecApplication) {
    if let Some(handle) = client_handle(connection) {
        handle
            .application
            .store(Some(Arc::new(application.clone())));
    }
}

/// Hands a request to the connection's client.
pub async fn on_request(
    connection: &mut AppSecConnection,
    request: AppSecRequest,
) -> AppSecVerdict {
    let Some(sender) = client_sender(connection) else {
        debug!(
            "AppSec request for session {} without AppSec config",
            session_id(connection)
        );
        return AppSecVerdict::Unavailable;
    };

    match exchange(&sender, request).await {
        Ok(verdict) => verdict,
        Err(e) => {
            let session = session_id(connection);
            match e {
                ExchangeError::SendClosed | ExchangeError::RecvClosed => {
                    warning!("AppSec client of session {session} is gone: {e}");
                }
                _ => error!("AppSec client of session {session} failed: {e}"),
            }
            // A stuck or dead client is replaced; the new one starts with the next request.
            restart_client(connection);
            AppSecVerdict::Unavailable
        }
    }
}

/// The connection is closed, or its session has no AppSec config anymore.
pub fn on_disconnect(connection: &mut AppSecConnection) {
    if connection.client.take().is_some() {
        debug!(
            "AppSec client of session {} dropped; it exits once idle",
            session_id(connection)
        );
    }
}

/// Starts a client task for a sidecar connection. The client submits telemetry with the
/// session, and reads the connection's application from the slot.
pub type NewClientFn = Box<dyn Fn(NewClient) -> ClientSender + Send + Sync>;

pub struct NewClient {
    pub session: ConnectionSessionHandle,
    pub config: AppSecSessionConfig,
    pub application: Arc<ArcSwapOption<AppSecApplication>>,
}

pub type ClientSender = mpsc::Sender<ClientRequest>;

/// One request of the extension, with the channel its verdict goes back on.
pub struct ClientRequest {
    pub request: AppSecRequest,
    pub response_tx: oneshot::Sender<AppSecVerdict>,
}

static NEW_CLIENT: ArcSwapOption<NewClientFn> = ArcSwapOption::const_empty();

const SEND_TIMEOUT: Duration = Duration::from_millis(750);
const RESPONSE_TIMEOUT: Duration = Duration::from_millis(3000);

/// The client of a connection, as kept in [`AppSecConnection::client`].
struct ClientHandle {
    sender: ClientSender,
    config: AppSecSessionConfig,
    application: Arc<ArcSwapOption<AppSecApplication>>,
}

fn client_handle(connection: &mut AppSecConnection) -> Option<&mut ClientHandle> {
    connection
        .client
        .as_mut()
        .and_then(|client| client.downcast_mut::<ClientHandle>())
}

fn client_sender(connection: &mut AppSecConnection) -> Option<ClientSender> {
    if client_handle(connection)?.sender.is_closed() {
        info!(
            "AppSec client of session {} exited; restarting it",
            session_id(connection)
        );
        restart_client(connection);
    }
    client_handle(connection).map(|handle| handle.sender.clone())
}

fn spawn_client(
    connection: &AppSecConnection,
    config: &AppSecSessionConfig,
    application: &Arc<ArcSwapOption<AppSecApplication>>,
) -> Option<ClientSender> {
    let Some(new_client) = NEW_CLIENT.load_full() else {
        info!("No new AppSec clients accepted (shutting down)");
        return None;
    };
    Some(new_client(NewClient {
        session: connection.session.clone(),
        config: config.clone(),
        application: application.clone(),
    }))
}

fn restart_client(connection: &mut AppSecConnection) {
    let Some(handle) = client_handle(connection) else {
        return;
    };
    let config = handle.config.clone();
    let application = handle.application.clone();
    match spawn_client(connection, &config, &application) {
        Some(sender) => {
            if let Some(handle) = client_handle(connection) {
                // Dropping the previous sender makes the previous client exit.
                handle.sender = sender;
            }
        }
        None => connection.client = None,
    }
}

async fn exchange(
    sender: &ClientSender,
    request: AppSecRequest,
) -> Result<AppSecVerdict, ExchangeError> {
    let (response_tx, response_rx) = oneshot::channel();
    let request = ClientRequest {
        request,
        response_tx,
    };
    match sender.try_send(request) {
        Ok(()) => {}
        Err(mpsc::error::TrySendError::Full(request)) => {
            timeout(SEND_TIMEOUT, sender.send(request))
                .await
                .map_err(|_| ExchangeError::SendTimeout)?
                .map_err(|_| ExchangeError::SendClosed)?;
        }
        Err(mpsc::error::TrySendError::Closed(_)) => return Err(ExchangeError::SendClosed),
    }
    timeout(RESPONSE_TIMEOUT, response_rx)
        .await
        .map_err(|_| ExchangeError::RecvTimeout)?
        .map_err(|_| ExchangeError::RecvClosed)
}

fn session_id(connection: &AppSecConnection) -> String {
    connection
        .session
        .load()
        .map(|session| session.instance_id.session_id.clone())
        .unwrap_or_default()
}

#[derive(Debug, Error)]
enum ExchangeError {
    #[error("timeout sending the request")]
    SendTimeout,
    #[error("channel closed sending the request")]
    SendClosed,
    #[error("timeout receiving the verdict")]
    RecvTimeout,
    #[error("channel closed receiving the verdict")]
    RecvClosed,
}

#[cfg(test)]
mod tests {
    use super::*;
    use serial_test::serial;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::OnceLock;

    fn test_runtime() -> &'static tokio::runtime::Runtime {
        static TEST_RUNTIME: OnceLock<tokio::runtime::Runtime> = OnceLock::new();
        TEST_RUNTIME.get_or_init(|| {
            tokio::runtime::Builder::new_multi_thread()
                .worker_threads(2)
                .enable_all()
                .build()
                .expect("test runtime should build")
        })
    }

    fn connection() -> AppSecConnection {
        AppSecConnection::new(ConnectionSessionHandle::default())
    }

    fn config(rules_file: &str) -> AppSecSessionConfig {
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

    fn request() -> AppSecRequest {
        AppSecRequest::Shutdown {
            addresses: Default::default(),
            api_sec_samp_key: 0,
            input_truncated: false,
        }
    }

    /// Clients answering with an error naming the application they see; `exits_after` requests,
    /// a client exits.
    fn echoing_clients(created: Arc<AtomicUsize>, exits_after: usize) -> NewClientFn {
        let rt = test_runtime().handle().clone();
        Box::new(move |new_client| {
            created.fetch_add(1, Ordering::SeqCst);
            let (tx, mut rx) = mpsc::channel::<ClientRequest>(1);
            rt.spawn(async move {
                for _ in 0..exits_after {
                    let Some(req) = rx.recv().await else {
                        return;
                    };
                    let seen = new_client
                        .application
                        .load()
                        .as_ref()
                        .map(|app| app.service.clone())
                        .unwrap_or_default();
                    let _ = req.response_tx.send(AppSecVerdict::Error(seen));
                }
            });
            tx
        })
    }

    #[test]
    #[serial]
    fn session_config_starts_one_client_per_config() {
        let created = Arc::new(AtomicUsize::new(0));
        start_accepting_messages(echoing_clients(created.clone(), usize::MAX));

        let mut a = connection();
        on_session_config(&mut a, &config(""));
        on_session_config(&mut a, &config(""));
        assert_eq!(created.load(Ordering::SeqCst), 1);
        on_session_config(&mut a, &config("/rules.json"));
        assert_eq!(created.load(Ordering::SeqCst), 2);
        on_session_config(&mut connection(), &config(""));
        assert_eq!(created.load(Ordering::SeqCst), 3);

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn requests_see_the_latest_application() {
        let created = Arc::new(AtomicUsize::new(0));
        start_accepting_messages(echoing_clients(created, usize::MAX));

        let mut a = connection();
        on_session_config(&mut a, &config(""));
        on_application(&mut a, &application("first"));
        on_application(&mut a, &application("second"));
        let verdict = test_runtime().block_on(on_request(&mut a, request()));
        assert_eq!(verdict, AppSecVerdict::Error("second".to_owned()));

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn request_without_session_config_is_unavailable() {
        start_accepting_messages(echoing_clients(Default::default(), usize::MAX));
        let verdict = test_runtime().block_on(on_request(&mut connection(), request()));
        assert_eq!(verdict, AppSecVerdict::Unavailable);
        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn exited_client_is_restarted_with_its_config_and_application() {
        let created = Arc::new(AtomicUsize::new(0));
        start_accepting_messages(echoing_clients(created.clone(), 1));

        let mut a = connection();
        on_session_config(&mut a, &config(""));
        on_application(&mut a, &application("svc"));
        let rt = test_runtime();
        assert_eq!(
            rt.block_on(on_request(&mut a, request())),
            AppSecVerdict::Error("svc".to_owned())
        );
        // The client exits after one request; wait until its channel says so.
        while !client_handle(&mut a).unwrap().sender.is_closed() {
            std::thread::yield_now();
        }
        assert_eq!(
            rt.block_on(on_request(&mut a, request())),
            AppSecVerdict::Error("svc".to_owned())
        );
        assert_eq!(created.load(Ordering::SeqCst), 2);

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn fork_cleanup_forgets_the_inherited_client_factory() {
        let created = Arc::new(AtomicUsize::new(0));
        start_accepting_messages(echoing_clients(created.clone(), usize::MAX));
        clear_inherited_state();

        let mut inherited = connection();
        on_session_config(&mut inherited, &config(""));
        assert!(inherited.client.is_none());
        assert_eq!(created.load(Ordering::SeqCst), 0);
    }

    #[test]
    #[serial]
    fn disconnect_drops_the_client() {
        start_accepting_messages(echoing_clients(Default::default(), usize::MAX));
        let mut a = connection();
        on_session_config(&mut a, &config(""));
        on_disconnect(&mut a);
        assert!(a.client.is_none());
        stop_accepting_messages();
    }
}
