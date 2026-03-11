use tokio::sync::{mpsc, oneshot};
use tokio::time::{timeout, Duration};

use crate::client::log::{debug, info, warning};
use crate::client::protocol::{self, CommandResponse};
use crate::{error, sidecar_symbol};

use crate::ffi::sidecar_ffi::{self, ddog_sidecar_appsec_register_message_handler};
use core::ffi::c_char;
use std::collections::HashMap;
use std::sync::{LazyLock, OnceLock, RwLock};
use thiserror::Error;

type ClientId = u64;
type NewClientFn = Box<dyn Fn(SessionId) -> (mpsc::Sender<HelperRequest>, ClientId) + Send + Sync>;
type SessionId = Vec<u8>;

#[derive(PartialEq, Eq, Hash)]
pub(crate) struct ClientKey {
    // session id is not strictly necessary, but could help with troubleshooting
    pub session_id: SessionId,
    pub client_id: ClientId,
}

static CLIENTS: LazyLock<std::sync::Mutex<HashMap<ClientKey, mpsc::Sender<HelperRequest>>>> =
    LazyLock::new(|| std::sync::Mutex::new(HashMap::new()));
static NEW_CLIENT: RwLock<Option<NewClientFn>> = RwLock::new(None);
static CALLBACK_RT_HANDLE: OnceLock<tokio::runtime::Handle> = OnceLock::new();

pub fn register_appsec_message_handlers(
    runtime_handle: tokio::runtime::Handle,
    new_client: NewClientFn,
) -> anyhow::Result<()> {
    SIDECAR_APPSEC_REGISTER_MESSAGE_HANDLER.resolve()?;
    CALLBACK_RT_HANDLE.get_or_init(|| runtime_handle);
    NEW_CLIENT
        .write()
        .expect("NEW_CLIENT not initialized")
        .replace(new_client);
    unsafe {
        SIDECAR_APPSEC_REGISTER_MESSAGE_HANDLER(
            Some(on_message),
            Some(on_disconnect),
            Some(free_response),
        );
    }

    Ok(())
}

pub fn unregister_appsec_message_handlers() -> anyhow::Result<()> {
    SIDECAR_APPSEC_REGISTER_MESSAGE_HANDLER.resolve()?;
    unsafe {
        SIDECAR_APPSEC_REGISTER_MESSAGE_HANDLER(None, None, Some(free_response));
    }

    CLIENTS.lock().expect("CLIENTS not initialized").clear();

    NEW_CLIENT
        .write()
        .expect("NEW_CLIENT not initialized")
        .take();
    Ok(())
}

type DdogSidecarAppsecRegisterMessageHandlerFn = unsafe extern "C" fn(
    on_message: sidecar_ffi::ddog_OnMessageFn,
    on_disconnect: sidecar_ffi::ddog_OnDisconnectFn,
    free_response: sidecar_ffi::ddog_FreeResponseFn,
);

sidecar_symbol!(
    static SIDECAR_APPSEC_REGISTER_MESSAGE_HANDLER = DdogSidecarAppsecRegisterMessageHandlerFn : ddog_sidecar_appsec_register_message_handler
);

/// A single framed message arriving from sidecar on behalf of the extension,
/// paired with the one-shot channel to send the response back.
pub struct HelperRequest {
    // owned by sidecar. Call is synchronous, so we can keep a static reference
    // and not copy the bytes
    pub command: &'static [u8],
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

extern "C" fn on_message(
    session_id_ptr: *const libc::c_char,
    session_id_len: usize,
    client_id: ClientId,
    data_ptr: *const u8,
    data_len: usize,
) -> sidecar_ffi::ddog_AppsecCResponse {
    let session_id =
        unsafe { std::slice::from_raw_parts(session_id_ptr as *const u8, session_id_len) };
    let data = unsafe { std::slice::from_raw_parts(data_ptr, data_len) };
    let res = on_message_impl(session_id, client_id, data);
    match res {
        Ok(HelperResponse::Data(data)) => {
            let mut data = std::mem::ManuallyDrop::new(data);
            let (ptr, len, capacity) = (data.as_mut_ptr(), data.len(), data.capacity());
            sidecar_ffi::ddog_AppsecCResponse {
                ptr,
                len,
                capacity,
                disconnect: false,
            }
        }
        Ok(HelperResponse::Reinitialize(data)) => {
            // The extension will redo client init on next request.
            // Destroy our client task as well by destroying the sender
            // task will get an eof
            // In general, the task will also exit, and deregister itself from
            // the client list, so this is just a safety fallback
            if client_id != 0 {
                remove_client_bookkeeping(&ClientKey {
                    session_id: session_id.to_vec(),
                    client_id,
                });
            }

            let mut data = std::mem::ManuallyDrop::new(data);
            let (ptr, len, capacity) = (data.as_mut_ptr(), data.len(), data.capacity());
            sidecar_ffi::ddog_AppsecCResponse {
                ptr,
                len,
                capacity,
                disconnect: true,
            }
        }
        Err(e) => {
            let session = String::from_utf8_lossy(session_id);
            match e.downcast_ref::<OnMessageError>() {
                Some(OnMessageError::ShuttingDown) => {
                    info!(
                        "Dropping message during shutdown (session={session}, client_id={client_id})"
                    );
                }
                Some(
                    OnMessageError::SendTimeout { .. }
                    | OnMessageError::SendClosed { .. }
                    | OnMessageError::RecvTimeout { .. }
                    | OnMessageError::RecvClosed { .. },
                ) => {
                    error!(
                        "Could not obtain response from client task (session={}, thread={}): {}",
                        session, client_id, e
                    );
                }
                Some(OnMessageError::RuntimeHandleUnavailable) => {
                    error!(
                        "Callback runtime not initialized (session={}, client_id={})",
                        session, client_id
                    );
                }
                None => {
                    error!(
                        "Could not obtain response from client task (session={}, thread={}): {:#}",
                        session, client_id, e
                    );
                }
            }

            remove_client_bookkeeping(&ClientKey {
                session_id: session_id.to_vec(),
                client_id,
            });

            use tokio_util::codec::Encoder;
            let encoded = {
                let mut buf = tokio_util::bytes::BytesMut::new();
                match protocol::CommandCodec.encode(CommandResponse::FatalError, &mut buf) {
                    Ok(()) => buf.to_vec(),
                    Err(encode_err) => {
                        error!(
                            "Could not encode fatal response after client failure: {}",
                            encode_err
                        );
                        Vec::new()
                    }
                }
            };
            let mut encoded = std::mem::ManuallyDrop::new(encoded);
            let (ptr, len, capacity) = (encoded.as_mut_ptr(), encoded.len(), encoded.capacity());
            sidecar_ffi::ddog_AppsecCResponse {
                ptr,
                len,
                capacity,
                disconnect: true,
            }
        }
    }
}

fn on_message_impl(
    session_id: &[u8],
    client_id: u64,
    command: &'static [u8],
) -> anyhow::Result<HelperResponse> {
    let request_tx = sender_for_client(ClientKey {
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

    let runtime_handle = callback_runtime_handle()
        .ok_or_else(|| anyhow::Error::new(OnMessageError::RuntimeHandleUnavailable))?;
    runtime_handle.block_on(async move {
        // send the request to the client task (wait max 750 ms for it to be queued)
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

        // wait for the response from the client task (wait max 3 seconds)
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
    })
}

fn callback_runtime_handle() -> Option<&'static tokio::runtime::Handle> {
    CALLBACK_RT_HANDLE.get()
}

fn sender_for_client(key: ClientKey) -> Option<mpsc::Sender<HelperRequest>> {
    if key.requesting_new_client() {
        return channel_for_new_client(key.session_id);
    }

    let clients = CLIENTS.lock().expect("CLIENTS not initialized");
    match clients.get(&key) {
        Some(sender) => Some(sender.clone()),
        None => {
            warning!("Client for {key:?} not found",);
            None
        }
    }
}

// Creates a new client and adds it to the client list
fn channel_for_new_client(session_id: SessionId) -> Option<mpsc::Sender<HelperRequest>> {
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
    Some(sender)
}

// This will also force the client to exit by destroying the sending part of
// the client channel
pub(crate) fn remove_client_bookkeeping(key: &ClientKey) {
    info!("Destroying client for {key:?}");
    let mut sessions = CLIENTS.lock().expect("CLIENTS not initialized");
    if sessions.remove(key).is_none() {
        debug!("Client for {key:?} not found, nothing to destroy");
    }
}

extern "C" fn on_disconnect(session_id_ptr: *const c_char, session_id_len: usize) {
    let session_id =
        unsafe { std::slice::from_raw_parts(session_id_ptr as *const u8, session_id_len) };
    debug!(
        "Disconnecting notification from sidecar for session: {}",
        String::from_utf8_lossy(session_id)
    );
    // Remove all (session_id, client_id) entries for this session — in ZTS
    // there may be one per worker thread.
    let mut sessions = CLIENTS.lock().expect("CLIENTS not initialized");
    sessions.retain(|key, _| key.session_id != session_id);
}

extern "C" fn free_response(ptr: *mut u8, len: usize, capacity: usize) {
    drop(unsafe { Vec::from_raw_parts(ptr, len, capacity) });
}

#[derive(Debug, Error)]
enum OnMessageError {
    #[error("No new clients accepted (we're shutting down)")]
    ShuttingDown,
    #[error("callback runtime handle not initialized")]
    RuntimeHandleUnavailable,
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

    fn ensure_callback_runtime_handle() {
        let handle = test_runtime().handle().clone();
        CALLBACK_RT_HANDLE.get_or_init(|| handle);
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

    fn read_response(resp: &sidecar_ffi::ddog_AppsecCResponse) -> Vec<u8> {
        if resp.ptr.is_null() || resp.len == 0 {
            Vec::new()
        } else {
            unsafe { std::slice::from_raw_parts(resp.ptr, resp.len) }.to_vec()
        }
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

        let first = sender_for_client(ClientKey {
            session_id: b"sess-a".to_vec(),
            client_id: 0,
        })
        .expect("first sender should exist");
        let second = sender_for_client(ClientKey {
            session_id: b"sess-a".to_vec(),
            client_id: 1,
        })
        .expect("second sender should exist");
        let third = sender_for_client(ClientKey {
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

        let _sender = sender_for_client(ClientKey {
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
        ensure_callback_runtime_handle();

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

        let response = on_message_impl(b"sess", 0, b"cmd").expect("message should succeed");
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

        let err = match on_message_impl(b"sess", 0, b"cmd") {
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
        ensure_callback_runtime_handle();

        set_new_client(Box::new(move |_session| {
            let (tx, rx) = mpsc::channel::<HelperRequest>(1);
            drop(rx);
            (tx, 1u64)
        }));

        let err = match on_message_impl(b"sess", 0, b"cmd") {
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
        ensure_callback_runtime_handle();

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

        let err = match on_message_impl(b"sess", 0, b"cmd") {
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
        ensure_callback_runtime_handle();

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
        let resp = on_message(
            session.as_ptr() as *const c_char,
            session.len(),
            0,
            payload.as_ptr(),
            payload.len(),
        );

        assert!(!resp.disconnect);
        assert_eq!(read_response(&resp), vec![7, 8]);
        free_response(resp.ptr, resp.len, resp.capacity);

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_success_reinitialize_sets_disconnect_true() {
        reset_test_state();
        ensure_callback_runtime_handle();

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
        let resp = on_message(
            session.as_ptr() as *const c_char,
            session.len(),
            0,
            payload.as_ptr(),
            payload.len(),
        );

        assert!(resp.disconnect);
        assert_eq!(read_response(&resp), vec![9]);
        free_response(resp.ptr, resp.len, resp.capacity);

        reset_test_state();
    }

    #[test]
    #[serial]
    fn on_message_error_path_sets_disconnect_true() {
        reset_test_state();
        ensure_callback_runtime_handle();

        let session = b"sess";
        let payload = b"cmd";
        let resp = on_message(
            session.as_ptr() as *const c_char,
            session.len(),
            0,
            payload.as_ptr(),
            payload.len(),
        );

        assert!(resp.disconnect);
        free_response(resp.ptr, resp.len, resp.capacity);
    }
}
