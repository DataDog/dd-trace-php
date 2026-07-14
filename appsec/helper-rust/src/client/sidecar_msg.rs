use tokio::sync::{mpsc, oneshot};
use tokio::time::{timeout, Duration};

use crate::client::log::{debug, info, warning};
use crate::client::protocol::{self, CommandResponse};
use crate::error;

use std::collections::HashMap;
use std::sync::{LazyLock, RwLock};
use thiserror::Error;

type ClientId = u64;
type NewClientFn = Box<dyn Fn(SessionId) -> (mpsc::Sender<HelperRequest>, ClientId) + Send + Sync>;
type SessionId = Vec<u8>;

#[derive(PartialEq, Eq, Hash, Clone)]
pub(crate) struct ClientKey {
    // session id is not strictly necessary, but could help with troubleshooting
    pub session_id: SessionId,
    pub client_id: ClientId,
}

static CLIENTS: LazyLock<std::sync::Mutex<HashMap<ClientKey, mpsc::Sender<HelperRequest>>>> =
    LazyLock::new(|| std::sync::Mutex::new(HashMap::new()));
static NEW_CLIENT: RwLock<Option<NewClientFn>> = RwLock::new(None);

pub fn start_accepting_messages(new_client: NewClientFn) {
    NEW_CLIENT
        .write()
        .expect("NEW_CLIENT not initialized")
        .replace(new_client);
}

pub fn stop_accepting_messages() {
    CLIENTS.lock().expect("CLIENTS not initialized").clear();
    NEW_CLIENT
        .write()
        .expect("NEW_CLIENT not initialized")
        .take();
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
    pub client_id: ClientId,
    pub data: Vec<u8>,
    pub disconnect: bool,
}

pub async fn on_message(session_id: &[u8], client_id: ClientId, data: Vec<u8>) -> MessageResponse {
    let res = on_message_impl(session_id, client_id, data).await;
    match res {
        Ok((resolved_id, HelperResponse::Data(data))) => MessageResponse {
            client_id: resolved_id,
            data,
            disconnect: false,
        },
        Ok((resolved_id, HelperResponse::Reinitialize(data))) => {
            // Reinitialize is only produced on fatal paths that make the client task
            // return. Its task wrapper also removes this bookkeeping on exit, so this
            // eager cleanup may safely race with it.
            if client_id != 0 {
                remove_client_bookkeeping(&ClientKey {
                    session_id: session_id.to_vec(),
                    client_id,
                });
            }

            MessageResponse {
                client_id: resolved_id,
                data,
                disconnect: true,
            }
        }
        Err(e) => {
            let session = String::from_utf8_lossy(session_id);
            match e.downcast_ref::<OnMessageError>() {
                Some(OnMessageError::ShuttingDown) => {
                    info!(
                        "Dropping message during shutdown (session={session}, \
                        client_id={client_id})"
                    );
                }
                Some(
                    OnMessageError::SendTimeout { .. }
                    | OnMessageError::SendClosed { .. }
                    | OnMessageError::RecvTimeout { .. }
                    | OnMessageError::RecvClosed { .. },
                ) => {
                    error!(
                        "Could not obtain response from client task (session={}, thread={}): {:#}",
                        session, client_id, e
                    );
                }
                None => {
                    error!(
                        "Could not obtain response from client task (session={}, thread={}): {:#}",
                        session, client_id, e
                    );
                }
            }

            if client_id != 0 {
                remove_client_bookkeeping(&ClientKey {
                    session_id: session_id.to_vec(),
                    client_id,
                });
            }

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
                client_id,
                data: encoded,
                disconnect: true,
            }
        }
    }
}

async fn on_message_impl(
    session_id: &[u8],
    client_id: ClientId,
    command: Vec<u8>,
) -> anyhow::Result<(ClientId, HelperResponse)> {
    let (request_tx, resolved_id) = sender_for_client(ClientKey {
        session_id: session_id.to_vec(),
        client_id,
    })
    .ok_or_else(|| anyhow::Error::new(OnMessageError::ShuttingDown))?;
    let session_id_prod = { || String::from_utf8_lossy(session_id).to_string() };

    let (response_tx, response_rx) = tokio::sync::oneshot::channel();
    let request = HelperRequest {
        command,
        response_tx,
    };

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

    Ok((resolved_id, response))
}

pub fn on_disconnect(session_id: &[u8], client_id: ClientId) {
    debug!(
        "Disconnect notification from sidecar: session={}, client_id={}",
        String::from_utf8_lossy(session_id),
        client_id,
    );
    if client_id == 0 {
        debug!(
            "Session-wide sweep: removing all clients for session {}",
            String::from_utf8_lossy(session_id)
        );
        let keys_to_remove: Vec<ClientKey> = CLIENTS
            .lock()
            .expect("CLIENTS not initialized")
            .keys()
            .filter(|key| key.session_id == session_id)
            .cloned()
            .collect();
        for key in &keys_to_remove {
            remove_client_bookkeeping(key);
        }
        return;
    }
    remove_client_bookkeeping(&ClientKey {
        session_id: session_id.to_vec(),
        client_id,
    });
}

fn sender_for_client(key: ClientKey) -> Option<(mpsc::Sender<HelperRequest>, ClientId)> {
    if key.requesting_new_client() {
        return channel_for_new_client(key.session_id);
    }

    let client_id = key.client_id;
    let clients = CLIENTS.lock().expect("CLIENTS not initialized");
    match clients.get(&key) {
        Some(sender) => Some((sender.clone(), client_id)),
        None => {
            warning!("Client for {key:?} not found",);
            None
        }
    }
}

// Creates a new client and adds it to the client list
fn channel_for_new_client(
    session_id: SessionId,
) -> Option<(mpsc::Sender<HelperRequest>, ClientId)> {
    let new_client = NEW_CLIENT.read().expect("NEW_CLIENT not initialized");
    if new_client.is_none() {
        info!("No new clients accepted (we're shutting down)");
        return None;
    }
    let (sender, client_id) = new_client.as_ref().unwrap()(session_id.clone());
    let mut clients = CLIENTS.lock().expect("CLIENTS not initialized");
    clients.insert(
        ClientKey {
            session_id,
            client_id,
        },
        sender.clone(),
    );
    Some((sender, client_id))
}

// This will also force the client to exit by destroying the sending part of
// the client channel
pub(crate) fn remove_client_bookkeeping(key: &ClientKey) {
    let mut sessions = CLIENTS.lock().expect("CLIENTS not initialized");
    if sessions.remove(key).is_none() {
        // normal if the client disconnected -> bookkeeping was removed ->
        // Forceful disconnect -> client exits -> tries to remove bookkeeping again
        debug!("Client booking for {key:?} not found");
    } else {
        debug!(
            "Client bookkeeping for {key:?} removed, \
               if client is still running it will trigger a ForcefulDisconnect and exit"
        );
    }
}

#[derive(Debug, Error)]
enum OnMessageError {
    #[error("No new clients accepted (we're shutting down)")]
    ShuttingDown,
    #[error("timeout sending request to helper client (session={session_id})")]
    SendTimeout { session_id: String },
    #[error("channel closed sending request to helper client (session={session_id})")]
    SendClosed { session_id: String },
    #[error("timeout receiving response from helper client (session={session_id})")]
    RecvTimeout { session_id: String },
    #[error("channel closed receiving response from helper client (session={session_id})")]
    RecvClosed { session_id: String },
}

impl ClientKey {
    fn requesting_new_client(&self) -> bool {
        self.client_id == 0
    }
}

impl std::fmt::Debug for ClientKey {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "ClientKey {{ session_id: {:?}, client_id: {} }}",
            String::from_utf8_lossy(&self.session_id),
            self.client_id
        )
    }
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

    fn reset_test_state() {
        CLIENTS.lock().expect("CLIENTS not initialized").clear();
        NEW_CLIENT
            .write()
            .expect("NEW_CLIENT not initialized")
            .take();
    }

    fn set_new_client(factory: NewClientFn) {
        NEW_CLIENT
            .write()
            .expect("NEW_CLIENT not initialized")
            .replace(factory);
    }

    #[test]
    #[serial]
    fn channel_for_session_reuses_existing_sender() {
        reset_test_state();

        let created = Arc::new(AtomicUsize::new(0));
        let created_in_factory = created.clone();
        set_new_client(Box::new(move |_session| {
            let id = (created_in_factory.fetch_add(1, Ordering::SeqCst) + 1) as u64;
            let (tx, _rx) = mpsc::channel(1);
            (tx, id)
        }));

        let (first, _) = sender_for_client(ClientKey {
            session_id: b"sess-a".to_vec(),
            client_id: 0,
        })
        .expect("first sender should exist");
        let (second, _) = sender_for_client(ClientKey {
            session_id: b"sess-a".to_vec(),
            client_id: 1,
        })
        .expect("second sender should exist");
        let (third, _) = sender_for_client(ClientKey {
            session_id: b"sess-b".to_vec(),
            client_id: 0,
        })
        .expect("third sender should exist");

        assert!(first.same_channel(&second));
        assert!(!first.same_channel(&third));
        assert_eq!(created.load(Ordering::SeqCst), 2);

        reset_test_state();
    }

    #[test]
    #[serial]
    fn channel_for_session_returns_none_when_new_client_disabled() {
        reset_test_state();
        assert!(sender_for_client(ClientKey {
            session_id: b"sess".to_vec(),
            client_id: 0,
        })
        .is_none());
    }

    #[test]
    #[serial]
    fn remove_client_bookkeeping_only_removes_matching_key() {
        reset_test_state();

        set_new_client(Box::new(move |_session| {
            let (tx, _rx) = mpsc::channel(1);
            (tx, 1u64)
        }));

        let (_sender, _) = sender_for_client(ClientKey {
            session_id: b"sess".to_vec(),
            client_id: 0,
        })
        .expect("sender should exist");
        let key = ClientKey {
            session_id: b"sess".to_vec(),
            client_id: 1,
        };

        remove_client_bookkeeping(&ClientKey {
            session_id: b"other".to_vec(),
            client_id: 1,
        });
        assert!(CLIENTS
            .lock()
            .expect("CLIENTS not initialized")
            .contains_key(&key));

        remove_client_bookkeeping(&key);
        assert!(!CLIENTS
            .lock()
            .expect("CLIENTS not initialized")
            .contains_key(&key));

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_impl_roundtrip_success() {
        reset_test_state();

        let rt = test_runtime().handle().clone();
        set_new_client(Box::new(move |_session| {
            let (tx, mut rx) = mpsc::channel::<HelperRequest>(1);
            rt.spawn(async move {
                if let Some(req) = rx.recv().await {
                    assert_eq!(req.command, b"cmd");
                    let _ = req.response_tx.send(HelperResponse::Data(vec![1, 2, 3]));
                }
            });
            (tx, 1u64)
        }));

        let (_, response) = test_runtime()
            .block_on(on_message_impl(b"sess", 0, b"cmd".to_vec()))
            .expect("message should succeed");
        match response {
            HelperResponse::Data(bytes) => assert_eq!(bytes, vec![1, 2, 3]),
            HelperResponse::Reinitialize(_) => panic!("expected normal data response"),
        }

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_impl_returns_typed_shutdown_error() {
        // without NEW_CLIENT set, the error is ShuttingDown
        reset_test_state();

        let err = match test_runtime().block_on(on_message_impl(b"sess", 0, b"cmd".to_vec())) {
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
        reset_test_state();

        set_new_client(Box::new(move |_session| {
            let (tx, rx) = mpsc::channel::<HelperRequest>(1);
            drop(rx);
            (tx, 1u64)
        }));

        let err = match test_runtime().block_on(on_message_impl(b"sess", 0, b"cmd".to_vec())) {
            Ok(_) => panic!("send should fail on closed channel"),
            Err(err) => err,
        };
        let typed = err
            .downcast_ref::<OnMessageError>()
            .expect("should be typed on-message error");
        assert!(
            matches!(typed, OnMessageError::SendClosed { session_id } if session_id == "sess" )
        );

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_impl_returns_typed_recv_closed_error() {
        reset_test_state();

        let rt = test_runtime().handle().clone();
        set_new_client(Box::new(move |_session| {
            let (tx, mut rx) = mpsc::channel::<HelperRequest>(1);
            rt.spawn(async move {
                if let Some(req) = rx.recv().await {
                    drop(req.response_tx);
                }
            });
            (tx, 1u64)
        }));

        let err = match test_runtime().block_on(on_message_impl(b"sess", 0, b"cmd".to_vec())) {
            Ok(_) => panic!("recv should fail when response channel closes"),
            Err(err) => err,
        };
        let typed = err
            .downcast_ref::<OnMessageError>()
            .expect("should be typed on-message error");
        assert!(matches!(typed, OnMessageError::RecvClosed { .. }));

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_success_data_sets_disconnect_false() {
        reset_test_state();

        let rt = test_runtime().handle().clone();
        set_new_client(Box::new(move |_session| {
            let (tx, mut rx) = mpsc::channel::<HelperRequest>(1);
            rt.spawn(async move {
                if let Some(req) = rx.recv().await {
                    let _ = req.response_tx.send(HelperResponse::Data(vec![7, 8]));
                }
            });
            (tx, 1u64)
        }));

        let session = b"sess";
        let payload = b"cmd";
        let resp = test_runtime().block_on(on_message(session, 0, payload.to_vec()));

        assert!(!resp.disconnect);
        assert_eq!(resp.data, vec![7, 8]);

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_success_reinitialize_sets_disconnect_true() {
        reset_test_state();

        let rt = test_runtime().handle().clone();
        set_new_client(Box::new(move |_session| {
            let (tx, mut rx) = mpsc::channel::<HelperRequest>(1);
            rt.spawn(async move {
                if let Some(req) = rx.recv().await {
                    let _ = req.response_tx.send(HelperResponse::Reinitialize(vec![9]));
                }
            });
            (tx, 1u64)
        }));

        let session = b"sess";
        let payload = b"cmd";
        let resp = test_runtime().block_on(on_message(session, 0, payload.to_vec()));

        assert!(resp.disconnect);
        assert_eq!(resp.data, vec![9]);

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_error_path_sets_disconnect_true() {
        reset_test_state();

        let session = b"sess";
        let payload = b"cmd";
        let resp = test_runtime().block_on(on_message(session, 0, payload.to_vec()));

        assert!(resp.disconnect);
    }
}
