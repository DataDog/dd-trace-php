use std::ffi::{CStr, OsStr};
use std::os::unix::ffi::OsStrExt;
use std::path::Path;
use std::sync::atomic::{AtomicPtr, Ordering};

use crate::ffi::sidecar_ffi::{
    ddog_Arc_Target, ddog_ConfigInvariants, ddog_remote_config_path,
    ddog_remote_config_path_free, ddog_set_rc_notify_fn,
};
use crate::service::ServiceManager;

static SERVICE_MANAGER: AtomicPtr<ServiceManager> = AtomicPtr::new(std::ptr::null_mut());

unsafe extern "C" fn rc_notify_callback(
    invariants: *const ddog_ConfigInvariants,
    target: *const ddog_Arc_Target,
) {
    let service_manager = SERVICE_MANAGER.load(Ordering::Acquire);
    if service_manager.is_null() {
        log::warn!("No service manager to notify of remote config updates");
        return;
    }

    let path = match RemoteConfigPath::new(invariants, target) {
        Ok(path) => path,
        Err(e) => {
            log::error!("Failed to get remote config path: {}", e);
            return;
        }
    };

    log::info!("Remote config updated notification for {:?}", path);

    let service_manager = unsafe { &*service_manager };
    service_manager.notify_of_rc_updates(path.as_ref());
}

pub fn resolve_symbols() -> Result<(), String> {
    Ok(())
}

pub fn register_for_rc_notifications(service_manager: &'static ServiceManager) {
    log::info!("Registering for RC update callbacks");

    SERVICE_MANAGER.store(service_manager as *const _ as *mut _, Ordering::Release);

    unsafe {
        ddog_set_rc_notify_fn(Some(rc_notify_callback));
    }
}

pub fn unregister_for_rc_notifications() {
    log::info!("Unregistering for RC update callbacks");

    unsafe {
        ddog_set_rc_notify_fn(None);
    }

    SERVICE_MANAGER.store(std::ptr::null_mut(), Ordering::Release);
}

struct RemoteConfigPath {
    buf: *mut std::ffi::c_char,
}
impl RemoteConfigPath {
    fn new(invariants: *const ddog_ConfigInvariants, target: *const ddog_Arc_Target) -> anyhow::Result<Self> {
        let buf = unsafe { ddog_remote_config_path(invariants, target) };
        if buf.is_null() {
            return Err(anyhow::anyhow!("ddog_remote_config_path returned null"));
        }
        Ok(Self { buf })
    }
}
impl Drop for RemoteConfigPath {
    fn drop(&mut self) {
        unsafe { ddog_remote_config_path_free(self.buf) };
    }
}
impl AsRef<Path> for RemoteConfigPath {
    fn as_ref(&self) -> &Path {
        unsafe { Path::new(OsStr::from_bytes(CStr::from_ptr(self.buf).to_bytes())) }
    }
}
impl std::fmt::Debug for RemoteConfigPath {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "RemoteConfigPath {:?}", self.as_ref())
    }
}
