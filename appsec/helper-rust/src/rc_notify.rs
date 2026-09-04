use std::ffi::OsStr;
use std::os::unix::ffi::OsStrExt;
use std::path::Path;
use std::sync::atomic::{AtomicPtr, Ordering};
use std::sync::Arc;

use datadog_sidecar::shm_remote_config::{ddog_set_rc_notify_fn, path_for_remote_config};
use libdd_remote_config::fetch::ConfigInvariants;
use libdd_remote_config::Target;

use crate::service::ServiceManager;

static SERVICE_MANAGER: AtomicPtr<ServiceManager> = AtomicPtr::new(std::ptr::null_mut());

extern "C" fn rc_notify_callback(invariants: *const ConfigInvariants, target: *const Arc<Target>) {
    let service_manager = SERVICE_MANAGER.load(Ordering::Acquire);
    if service_manager.is_null() {
        log::warn!("No service manager to notify of remote config updates");
        return;
    }

    let path = unsafe { path_for_remote_config(&*invariants, &*target) };
    let path = Path::new(OsStr::from_bytes(path.as_bytes()));

    log::info!("Remote config updated notification for {:?}", path);

    let service_manager = unsafe { &*service_manager };
    service_manager.notify_of_rc_updates(path);
}

pub fn register_for_rc_notifications(service_manager: &'static ServiceManager) {
    log::info!("Registering for RC update callbacks");

    SERVICE_MANAGER.store(service_manager as *const _ as *mut _, Ordering::Release);

    unsafe { ddog_set_rc_notify_fn(Some(rc_notify_callback)) };
}

pub fn unregister_for_rc_notifications() {
    log::info!("Unregistering for RC update callbacks");

    unsafe { ddog_set_rc_notify_fn(None) };

    SERVICE_MANAGER.store(std::ptr::null_mut(), Ordering::Release);
}
