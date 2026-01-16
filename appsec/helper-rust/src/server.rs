use anyhow::Context;
use tokio::net::UnixListener;
use tokio_util::future::FutureExt;
use tokio_util::sync::CancellationToken;

use crate::client::Client;
use crate::config::Config;
use crate::rc_notify;
use crate::service::ServiceManager;

/// Run the Unix socket server that accepts client connections
///
/// This function:
/// - Binds to the configured Unix socket
/// - Accepts incoming client connections
/// - Spawns a task for each client
/// - Monitors the cancellation token for shutdown
///
/// Returns when the cancellation token is triggered
pub async fn run_server(config: Config, cancel_token: CancellationToken) -> anyhow::Result<()> {
    let socket_path = config.socket_path_as_path();

    log::info!("Starting server on socket: {:?}", socket_path);

    #[cfg(not(target_os = "linux"))]
    if config.is_abstract_socket() {
        anyhow::bail!("Abstract namespace sockets are only supported on Linux");
    }

    // tokio handles abstract namespace sockets on Linux
    let listener = UnixListener::bind(&socket_path)
        .with_context(|| format!("binding unix socket {:?}", &socket_path))?;

    log::info!("Listening for connections");

    // Create service manager with 'static lifetime
    // We leak it since it needs to live for the entire process lifetime
    let service_manager: &'static ServiceManager = Box::leak(Box::new(ServiceManager::new()));

    // Register for RC update callbacks from the sidecar
    rc_notify::register_for_rc_notifications(service_manager);

    loop {
        match listener
            .accept()
            .with_cancellation_token(&cancel_token)
            .await
        {
            Some(Ok((stream, addr))) => {
                log::debug!("Accepted new client {:?}", addr);

                let client = Client::new(service_manager);
                let token = cancel_token.clone();

                tokio::spawn(async move { client.entrypoint(stream, token).await });
            }
            Some(Err(err)) => {
                log::warn!("Error in accept() call: {}", err);
            }
            None => {
                log::info!("Server received cancellation signal, shutting down");
                break;
            }
        }
    }

    if !config.is_abstract_socket() {
        if let Err(e) = std::fs::remove_file(&socket_path) {
            log::warn!("Failed to remove socket file: {}", e);
        }
    }

    rc_notify::unregister_for_rc_notifications();

    log::info!("Server shutdown complete");
    Ok(())
}
