use datadog_sidecar::service::blocking::{ping, SidecarTransport};
use libdd_ipc::SeqpacketConn;

fn main() {
    let socket_path = std::env::args_os()
        .nth(1)
        .expect("sidecar socket path argument is required");
    let connection = SeqpacketConn::connect(socket_path).expect("connect to sidecar listener");
    let mut transport = SidecarTransport::from(connection);
    ping(&mut transport).expect("sidecar ping response");
    println!("sidecar ping passed");
}
