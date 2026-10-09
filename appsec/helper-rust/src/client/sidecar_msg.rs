use tokio::sync::{mpsc, oneshot};
use tokio::time::{timeout, Duration};

use crate::client::log::{debug, info, warning};
use crate::client::protocol::{self, CommandResponse};
use crate::error;

use arc_swap::ArcSwapOption;
use datadog_sidecar::appsec::AppSecConnection;
use datadog_sidecar::service::ConnectionSessionHandle;
use std::sync::Arc;
use thiserror::Error;

/// Starts a client for a sidecar connection, whose session the client submits telemetry with.
type NewClientFn = Box<dyn Fn(ConnectionSessionHandle) -> ClientSender + Send + Sync>;

/// The client of a connection, as kept in [`AppSecConnection::client`].
type ClientSender = mpsc::Sender<HelperRequest>;

static NEW_CLIENT: ArcSwapOption<NewClientFn> = ArcSwapOption::const_empty();

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

/// A single framed message arriving from sidecar on behalf of the extension,
/// paired with the one-shot channel to send the response back.
pub struct HelperRequest {
    pub command: Vec<u8>,
    pub response_tx: oneshot::Sender<HelperResponse>,
}

/// Response produced by the client task for one `HelperRequest`.
pub enum HelperResponse {
    /// Normal response: forward these bytes to the extension and continue.
    Data(Vec<u8>),
    /// Error/shutdown response: forward these bytes (if any) then have the
    /// extension redo client init on next request.
    Reinitialize(Vec<u8>),
}

pub struct MessageResponse {
    pub data: Vec<u8>,
    pub disconnect: bool,
}

/// Handles one message of a connection. A client_init starts a new client for the connection,
/// replacing its previous one; any other message goes to the client of the connection.
pub async fn on_message(connection: &mut AppSecConnection, data: Vec<u8>) -> MessageResponse {
    let res = on_message_impl(connection, data).await;
    match res {
        Ok(HelperResponse::Data(data)) => MessageResponse {
            data,
            disconnect: false,
        },
        Ok(HelperResponse::Reinitialize(data)) => {
            // Reinitialize is only produced on fatal paths that make the client task return.
            forget_client(connection);
            MessageResponse {
                data,
                disconnect: true,
            }
        }
        Err(e) => {
            let session = session_id(connection);
            match e.downcast_ref::<OnMessageError>() {
                Some(OnMessageError::ShuttingDown) => {
                    info!("Dropping message during shutdown (session={session})");
                }
                Some(OnMessageError::NoClient) => {
                    warning!(
                        "Message for a connection without client (session={session}); \
                         the sidecar may have restarted"
                    );
                }
                _ => {
                    error!("Could not obtain response from client task (session={session}): {e:#}");
                }
            }

            forget_client(connection);

            use tokio_util::codec::Encoder;
            let encoded = {
                let mut buf = tokio_util::bytes::BytesMut::new();
                match protocol::CommandCodec.encode(CommandResponse::FatalError, &mut buf) {
                    Ok(()) => buf.into(),
                    Err(encode_err) => {
                        error!(
                            "Could not encode fatal response after client failure: {:#}",
                            encode_err
                        );
                        Vec::new()
                    }
                }
            };
            MessageResponse {
                data: encoded,
                disconnect: true,
            }
        }
    }
}

async fn on_message_impl(
    connection: &mut AppSecConnection,
    command: Vec<u8>,
) -> anyhow::Result<HelperResponse> {
    let request_tx = client_for_message(connection, protocol::is_client_init(&command))?;
    let session_id_prod = || session_id(connection);

    let (response_tx, response_rx) = tokio::sync::oneshot::channel();
    let request = HelperRequest {
        command,
        response_tx,
    };

    match request_tx.try_send(request) {
        Ok(()) => {}
        Err(mpsc::error::TrySendError::Full(request)) => {
            timeout(Duration::from_millis(750), request_tx.send(request))
                .await
                .map_err(|_| {
                    anyhow::Error::new(OnMessageError::SendTimeout {
                        session_id: session_id_prod(),
                    })
                })?
                .map_err(|_| {
                    anyhow::Error::new(OnMessageError::SendClosed {
                        session_id: session_id_prod(),
                    })
                })?;
        }
        Err(mpsc::error::TrySendError::Closed(_)) => {
            return Err(anyhow::Error::new(OnMessageError::SendClosed {
                session_id: session_id_prod(),
            }));
        }
    };

    let response = timeout(Duration::from_millis(3000), response_rx)
        .await
        .map_err(|_| {
            anyhow::Error::new(OnMessageError::RecvTimeout {
                session_id: session_id_prod(),
            })
        })?
        .map_err(|_| {
            anyhow::Error::new(OnMessageError::RecvClosed {
                session_id: session_id_prod(),
            })
        })?;

    Ok(response)
}

/// The connection is closed: its client exits once it sees its channel closed.
pub fn on_disconnect(connection: &mut AppSecConnection) {
    debug!(
        "Disconnect notification from sidecar: session={}",
        session_id(connection)
    );
    forget_client(connection);
}

fn client_for_message(
    connection: &mut AppSecConnection,
    client_init: bool,
) -> Result<ClientSender, OnMessageError> {
    let Some(new_client) = NEW_CLIENT.load_full() else {
        return Err(OnMessageError::ShuttingDown);
    };
    if client_init {
        // Dropping the previous client's channel makes it exit.
        let sender = new_client(connection.session.clone());
        connection.client = Some(Box::new(sender.clone()));
        return Ok(sender);
    }
    connection
        .client
        .as_ref()
        .and_then(|client| client.downcast_ref::<ClientSender>())
        .cloned()
        .ok_or(OnMessageError::NoClient)
}

// This will also force the client to exit by destroying the sending part of
// the client channel
fn forget_client(connection: &mut AppSecConnection) {
    if connection.client.take().is_some() {
        debug!(
            "Client of session {} forgotten, \
             if it is still running it will trigger a ForcefulDisconnect and exit",
            session_id(connection)
        );
    }
}

fn session_id(connection: &AppSecConnection) -> String {
    connection
        .session
        .load()
        .map(|session| session.instance_id.session_id.clone())
        .unwrap_or_default()
}

#[derive(Debug, Error)]
enum OnMessageError {
    #[error("No new clients accepted (we're shutting down)")]
    ShuttingDown,
    #[error("no client for the connection")]
    NoClient,
    #[error("timeout sending request to helper client (session={session_id})")]
    SendTimeout { session_id: String },
    #[error("channel closed sending request to helper client (session={session_id})")]
    SendClosed { session_id: String },
    #[error("timeout receiving response from helper client (session={session_id})")]
    RecvTimeout { session_id: String },
    #[error("channel closed receiving response from helper client (session={session_id})")]
    RecvClosed { session_id: String },
}

#[cfg(test)]
mod tests {
    use super::*;
    use serial_test::serial;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::{Arc, OnceLock};

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

    fn test_request(command: &[u8]) -> HelperRequest {
        let (response_tx, _response_rx) = oneshot::channel();
        HelperRequest {
            command: command.to_vec(),
            response_tx,
        }
    }

    fn connection() -> AppSecConnection {
        AppSecConnection::new(ConnectionSessionHandle::default())
    }

    /// A framed `[name, nil]` command.
    fn command(name: &str) -> Vec<u8> {
        let mut body = vec![0x92, 0xa0 | name.len() as u8];
        body.extend_from_slice(name.as_bytes());
        body.push(0xc0);
        let mut message = b"dds\0".to_vec();
        message.extend_from_slice(&(body.len() as u32).to_le_bytes());
        message.extend_from_slice(&body);
        message
    }

    fn client_init() -> Vec<u8> {
        command("client_init")
    }

    /// A client which answers every request with `data`.
    fn answering_client(data: Vec<u8>) -> NewClientFn {
        let rt = test_runtime().handle().clone();
        Box::new(move |_session| {
            let (tx, mut rx) = mpsc::channel::<HelperRequest>(1);
            let data = data.clone();
            rt.spawn(async move {
                while let Some(req) = rx.recv().await {
                    let _ = req.response_tx.send(HelperResponse::Data(data.clone()));
                }
            });
            tx
        })
    }

    #[test]
    #[serial]
    fn fork_cleanup_forgets_the_inherited_client_factory() {
        start_accepting_messages(answering_client(vec![1]));
        let mut inherited = connection();
        assert!(client_for_message(&mut inherited, true).is_ok());

        clear_inherited_state();
        assert!(matches!(
            client_for_message(&mut inherited, false),
            Err(OnMessageError::ShuttingDown)
        ));

        start_accepting_messages(answering_client(vec![2]));
        assert!(client_for_message(&mut connection(), true).is_ok());
        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn client_init_starts_a_client_which_other_commands_reuse() {
        stop_accepting_messages();

        let created = Arc::new(AtomicUsize::new(0));
        let created_in_factory = created.clone();
        start_accepting_messages(Box::new(move |_session| {
            created_in_factory.fetch_add(1, Ordering::SeqCst);
            mpsc::channel(1).0
        }));

        let mut a = connection();
        let first = client_for_message(&mut a, true).expect("client_init starts a client");
        let second = client_for_message(&mut a, false).expect("the client is reused");
        let replaced = client_for_message(&mut a, true).expect("client_init replaces the client");
        let other =
            client_for_message(&mut connection(), true).expect("other connections get theirs");

        assert!(first.same_channel(&second));
        assert!(!first.same_channel(&replaced));
        assert!(!replaced.same_channel(&other));
        assert_eq!(created.load(Ordering::SeqCst), 3);

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn command_without_client_init_has_no_client() {
        stop_accepting_messages();
        start_accepting_messages(answering_client(vec![]));

        assert!(matches!(
            client_for_message(&mut connection(), false),
            Err(OnMessageError::NoClient)
        ));

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn shutdown_accepts_no_messages() {
        stop_accepting_messages();
        assert!(matches!(
            client_for_message(&mut connection(), true),
            Err(OnMessageError::ShuttingDown)
        ));
    }

    #[test]
    #[serial]
    fn disconnect_only_forgets_the_client_of_its_connection() {
        stop_accepting_messages();
        start_accepting_messages(answering_client(vec![]));

        let mut a = connection();
        let mut b = connection();
        client_for_message(&mut a, true).expect("client for a");
        client_for_message(&mut b, true).expect("client for b");

        on_disconnect(&mut a);
        assert!(matches!(
            client_for_message(&mut a, false),
            Err(OnMessageError::NoClient)
        ));
        assert!(client_for_message(&mut b, false).is_ok());

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn on_message_impl_uses_timed_fallback_when_channel_is_full() {
        stop_accepting_messages();

        let receiver = Arc::new(std::sync::Mutex::new(None));
        let receiver_in_factory = receiver.clone();
        start_accepting_messages(Box::new(move |_session| {
            let (tx, rx) = mpsc::channel::<HelperRequest>(1);
            receiver_in_factory
                .lock()
                .expect("receiver mutex poisoned")
                .replace(rx);
            tx
        }));

        let mut connection = connection();
        let sender = client_for_message(&mut connection, true).expect("sender should exist");
        assert!(sender.try_send(test_request(b"first")).is_ok());

        let mut receiver = receiver
            .lock()
            .expect("receiver mutex poisoned")
            .take()
            .expect("receiver should exist");

        let response = test_runtime().block_on(async move {
            timeout(Duration::from_secs(1), async move {
                let message = tokio::spawn(async move {
                    on_message_impl(&mut connection, command("second")).await
                });
                tokio::task::yield_now().await;

                let first = receiver
                    .recv()
                    .await
                    .expect("first request should be queued");
                assert_eq!(first.command, b"first");
                drop(first);

                let second = receiver
                    .recv()
                    .await
                    .expect("fallback should queue the second request");
                assert_eq!(second.command, command("second"));
                second
                    .response_tx
                    .send(HelperResponse::Data(b"response".to_vec()))
                    .map_err(|_| ())
                    .expect("response receiver should be open");

                message
                    .await
                    .expect("message task should not panic")
                    .expect("message should succeed")
            })
            .await
            .expect("full-channel fallback should complete before send timeout")
        });
        assert!(matches!(
            response,
            HelperResponse::Data(data) if data == b"response"
        ));

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn on_message_impl_roundtrip_success() {
        stop_accepting_messages();
        start_accepting_messages(answering_client(vec![1, 2, 3]));

        let mut connection = connection();
        let response = test_runtime()
            .block_on(on_message_impl(&mut connection, client_init()))
            .expect("message should succeed");
        match response {
            HelperResponse::Data(bytes) => assert_eq!(bytes, vec![1, 2, 3]),
            HelperResponse::Reinitialize(_) => panic!("expected normal data response"),
        }

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn on_message_impl_returns_typed_shutdown_error() {
        // Without an active listener, the error is ShuttingDown.
        stop_accepting_messages();

        let err = match test_runtime().block_on(on_message_impl(&mut connection(), client_init())) {
            Ok(_) => panic!("should fail in shutdown mode"),
            Err(err) => err,
        };
        let typed = err
            .downcast_ref::<OnMessageError>()
            .expect("should be typed on-message error");
        assert!(matches!(typed, OnMessageError::ShuttingDown));
    }

    #[test]
    #[serial]
    fn on_message_impl_returns_typed_send_closed_error() {
        stop_accepting_messages();

        start_accepting_messages(Box::new(move |_session| {
            let (tx, rx) = mpsc::channel::<HelperRequest>(1);
            drop(rx);
            tx
        }));

        let err = match test_runtime().block_on(on_message_impl(&mut connection(), client_init())) {
            Ok(_) => panic!("send should fail on closed channel"),
            Err(err) => err,
        };
        let typed = err
            .downcast_ref::<OnMessageError>()
            .expect("should be typed on-message error");
        assert!(matches!(typed, OnMessageError::SendClosed { .. }));

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn on_message_impl_returns_typed_recv_closed_error() {
        stop_accepting_messages();

        let rt = test_runtime().handle().clone();
        start_accepting_messages(Box::new(move |_session| {
            let (tx, mut rx) = mpsc::channel::<HelperRequest>(1);
            rt.spawn(async move {
                if let Some(req) = rx.recv().await {
                    drop(req.response_tx);
                }
            });
            tx
        }));

        let err = match test_runtime().block_on(on_message_impl(&mut connection(), client_init())) {
            Ok(_) => panic!("recv should fail when response channel closes"),
            Err(err) => err,
        };
        let typed = err
            .downcast_ref::<OnMessageError>()
            .expect("should be typed on-message error");
        assert!(matches!(typed, OnMessageError::RecvClosed { .. }));

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn on_message_success_data_sets_disconnect_false() {
        stop_accepting_messages();
        start_accepting_messages(answering_client(vec![7, 8]));

        let mut connection = connection();
        let resp = test_runtime().block_on(on_message(&mut connection, client_init()));
        assert!(!resp.disconnect);
        assert_eq!(resp.data, vec![7, 8]);

        // The client of the connection keeps serving the following commands.
        let resp = test_runtime().block_on(on_message(&mut connection, command("request_init")));
        assert!(!resp.disconnect);
        assert_eq!(resp.data, vec![7, 8]);

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn on_message_success_reinitialize_sets_disconnect_true() {
        stop_accepting_messages();

        let rt = test_runtime().handle().clone();
        start_accepting_messages(Box::new(move |_session| {
            let (tx, mut rx) = mpsc::channel::<HelperRequest>(1);
            rt.spawn(async move {
                if let Some(req) = rx.recv().await {
                    let _ = req.response_tx.send(HelperResponse::Reinitialize(vec![9]));
                }
            });
            tx
        }));

        let mut connection = connection();
        let resp = test_runtime().block_on(on_message(&mut connection, client_init()));
        assert!(resp.disconnect);
        assert_eq!(resp.data, vec![9]);
        // The connection has to start over with client_init.
        assert!(matches!(
            client_for_message(&mut connection, false),
            Err(OnMessageError::NoClient)
        ));

        stop_accepting_messages();
    }

    #[test]
    #[serial]
    fn on_message_error_path_sets_disconnect_true() {
        stop_accepting_messages();

        let resp = test_runtime().block_on(on_message(&mut connection(), client_init()));
        assert!(resp.disconnect);
    }

    #[test]
    #[serial]
    fn on_message_without_client_sets_disconnect_true() {
        stop_accepting_messages();
        start_accepting_messages(answering_client(vec![1]));

        let resp = test_runtime().block_on(on_message(&mut connection(), command("request_init")));
        assert!(resp.disconnect);

        stop_accepting_messages();
    }
}
