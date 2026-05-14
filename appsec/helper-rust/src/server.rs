use std::future::Future;
use std::pin::Pin;
use std::time::Duration;

use tokio::sync::mpsc;
use tokio::task::JoinSet;
use tokio_util::sync::CancellationToken;

use crate::client::{self, Client};
use crate::rc_notify;
use crate::service::ServiceManager;

type ClientFuture = Pin<Box<dyn Future<Output = ()> + Send + 'static>>;

/// Start accepting helper messages from sidecar.
///
/// Returns when the cancellation token is triggered
pub fn accept_appsec_messages(
    runtime_handle: tokio::runtime::Handle,
    cancel_token: CancellationToken,
) -> anyhow::Result<ClientTaskSet> {
    log::info!("Starting to listen for helper messages from sidecar");

    // Create service manager with 'static lifetime
    // We leak it since it needs to live for the entire process lifetime
    let service_manager: &'static ServiceManager = Box::leak(Box::new(ServiceManager::new()));
    let (client_task_set, future_tx) = ClientTaskSet::create(runtime_handle.clone());

    // Register for RC update callbacks from the sidecar
    rc_notify::register_for_rc_notifications(service_manager);

    client::register_appsec_message_handlers(
        runtime_handle.clone(),
        // new_client callback:
        Box::new(move |session_id: Vec<u8>| {
            let client = Client::new(service_manager);
            log::info!(
                "Created client for session {}: id {}",
                String::from_utf8_lossy(&session_id),
                client.id
            );
            let client_id = client.id;
            let client_key = client::ClientKey {
                session_id,
                client_id,
            };

            let (tx, rx) = mpsc::channel(5);

            let future_tx = future_tx.clone();
            let cancel_token = cancel_token.clone();

            let client_future = client.entrypoint(rx, cancel_token);
            runtime_handle.spawn(async move {
                let managed_future = async move {
                    client_future.await;
                    log::debug!(
                        "Client future for {client_key:?} completed; removing client bookkeeping"
                    );
                    client::remove_client_bookkeeping(&client_key);
                };
                if let Err(e) = future_tx.send(Box::pin(managed_future)).await {
                    log::error!("Failed to send client future: {}", e);
                }
            });

            (tx, client_id)
        }),
    )?;

    Ok(client_task_set)
}

pub fn stop_accepting_appsec_messages() -> anyhow::Result<()> {
    rc_notify::unregister_for_rc_notifications();
    client::unregister_appsec_message_handlers()?;
    Ok(())
}

// Spawns a task that receives client futures and spawns them in a join set
pub struct ClientTaskSet {
    managing_task_handle: tokio::task::JoinHandle<()>,
    runtime_handle: tokio::runtime::Handle,
}

impl ClientTaskSet {
    fn create(runtime_handle: tokio::runtime::Handle) -> (Self, mpsc::Sender<ClientFuture>) {
        let (tx, rx) = mpsc::channel::<Pin<Box<dyn Future<Output = ()> + Send + 'static>>>(10);

        (
            Self {
                managing_task_handle: runtime_handle.clone().spawn(Self::task_entrypoint(rx)),
                runtime_handle,
            },
            tx,
        )
    }

    async fn task_entrypoint(
        mut rx: mpsc::Receiver<Pin<Box<dyn Future<Output = ()> + Send + 'static>>>,
    ) {
        let mut join_set = JoinSet::<()>::new();

        // We accept new futures to be spawned as long as we have senders
        while let Some(future) = rx.recv().await {
            join_set.spawn(future);
            Self::reap(&mut join_set);
        }

        // Afterwards, we wait for all tasks to complete
        join_set.join_all().await;
    }

    /// Drain all tasks that have already finished (non-blocking).
    fn reap(join_set: &mut JoinSet<()>) {
        while let Some(result) = join_set.try_join_next() {
            if let Err(e) = result {
                log::error!("Task failed: {}", e);
            }
        }
    }

    // Wait until the task finishes. This happens after new client generation
    // is stopped by destroying the sender of futures and after all client
    // tasks have finished (they have cooperative cancellation)
    pub fn wait_empty(&mut self, duration: Duration) -> bool {
        let managing_task_handle = &mut self.managing_task_handle;
        self.runtime_handle.block_on(async move {
            tokio::time::timeout(duration, managing_task_handle)
                .await
                .is_ok()
        })
    }
}
