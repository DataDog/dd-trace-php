use tokio_util::{sync::CancellationToken, task::TaskTracker};

use datadog_sidecar::service::telemetry::InProcessTelemetryClientFactory;

use crate::client;
use crate::rc_notify;
use crate::service::ServiceManager;

/// Start serving the AppSec requests of the sidecar's connections.
///
/// Returns a tracker that can be closed and awaited during shutdown.
pub fn accept_appsec_messages(
    runtime_handle: tokio::runtime::Handle,
    cancel_token: CancellationToken,
    telemetry: InProcessTelemetryClientFactory,
) -> TaskTracker {
    log::info!("Starting to serve AppSec requests from sidecar");

    // Create service manager with 'static lifetime
    // We leak it since it needs to live for the entire process lifetime
    let service_manager: &'static ServiceManager = Box::leak(Box::new(ServiceManager::new()));
    let client_task_tracker = TaskTracker::new();

    // Register for RC update callbacks from the sidecar
    rc_notify::register_for_rc_notifications(service_manager);

    client::start_accepting_messages(client::new_client_fn(
        service_manager,
        telemetry,
        runtime_handle,
        cancel_token,
        client_task_tracker.clone(),
    ));

    client_task_tracker
}

pub fn stop_accepting_appsec_messages() {
    rc_notify::unregister_for_rc_notifications();
    client::stop_accepting_messages();
}
