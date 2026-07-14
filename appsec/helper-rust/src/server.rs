use tokio::sync::mpsc;
use tokio_util::{sync::CancellationToken, task::TaskTracker};

use crate::client::{self, Client};
use crate::rc_notify;
use crate::service::ServiceManager;

/// Start accepting helper messages from sidecar.
///
/// Returns a tracker that can be closed and awaited during shutdown.
pub fn accept_appsec_messages(
    runtime_handle: tokio::runtime::Handle,
    cancel_token: CancellationToken,
) -> TaskTracker {
    log::info!("Starting to listen for helper messages from sidecar");

    // Create service manager with 'static lifetime
    // We leak it since it needs to live for the entire process lifetime
    let service_manager: &'static ServiceManager = Box::leak(Box::new(ServiceManager::new()));
    let client_task_tracker = TaskTracker::new();
    let task_tracker = client_task_tracker.clone();

    // Register for RC update callbacks from the sidecar
    rc_notify::register_for_rc_notifications(service_manager);

    client::start_accepting_messages(
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

            let cancel_token = cancel_token.clone();

            let client_future = client.entrypoint(rx, cancel_token);
            task_tracker.spawn_on(
                async move {
                    client_future.await;
                    log::debug!(
                        "Client future for {client_key:?} completed; removing client bookkeeping"
                    );
                    client::remove_client_bookkeeping(&client_key);
                },
                &runtime_handle,
            );

            (tx, client_id)
        }),
    );

    client_task_tracker
}

pub fn stop_accepting_appsec_messages() {
    rc_notify::unregister_for_rc_notifications();
    client::stop_accepting_messages();
}
