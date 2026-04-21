use std::ffi::{c_char, c_void, CStr, OsStr};
use std::os::unix::ffi::OsStrExt;
use std::path::Path;
use std::sync::atomic::{AtomicPtr, Ordering};

use crate::service::ServiceManager;

type InProcNotifyFn = extern "C" fn(*const c_void, *const c_void);
type DdogRemoteConfigPathFn = extern "C" fn(*const c_void, *const c_void) -> *mut c_char;
type DdogRemoteConfigPathFreeFn = extern "C" fn(*mut c_char);

static mut DDOG_SET_RC_NOTIFY_FN: Option<extern "C" fn(Option<InProcNotifyFn>)> = None;
static mut DDOG_REMOTE_CONFIG_PATH: Option<DdogRemoteConfigPathFn> = None;
static mut DDOG_REMOTE_CONFIG_PATH_FREE: Option<DdogRemoteConfigPathFreeFn> = None;

static SERVICE_MANAGER: AtomicPtr<ServiceManager> = AtomicPtr::new(std::ptr::null_mut());

extern "C" fn rc_notify_callback(invariants: *const c_void, target: *const c_void) {
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
    unsafe {
        let set_fn = libc::dlsym(libc::RTLD_DEFAULT, c"ddog_set_rc_notify_fn".as_ptr());
        if set_fn.is_null() {
            return Err("Failed to resolve ddog_set_rc_notify_fn".to_string());
        }
        DDOG_SET_RC_NOTIFY_FN = Some(std::mem::transmute::<
            *mut libc::c_void,
            extern "C" fn(Option<InProcNotifyFn>),
        >(set_fn));

        let path_fn = libc::dlsym(libc::RTLD_DEFAULT, c"ddog_remote_config_path".as_ptr());
        if path_fn.is_null() {
            return Err("Failed to resolve ddog_remote_config_path".to_string());
        }
        DDOG_REMOTE_CONFIG_PATH = Some(std::mem::transmute::<
            *mut libc::c_void,
            DdogRemoteConfigPathFn,
        >(path_fn));

        let path_free_fn =
            libc::dlsym(libc::RTLD_DEFAULT, c"ddog_remote_config_path_free".as_ptr());
        if path_free_fn.is_null() {
            return Err("Failed to resolve ddog_remote_config_path_free".to_string());
        }
        DDOG_REMOTE_CONFIG_PATH_FREE = Some(std::mem::transmute::<
            *mut libc::c_void,
            DdogRemoteConfigPathFreeFn,
        >(path_free_fn));
    }

    Ok(())
}

pub fn register_for_rc_notifications(service_manager: &'static ServiceManager) {
    log::info!("Registering for RC update callbacks");

    SERVICE_MANAGER.store(service_manager as *const _ as *mut _, Ordering::Release);

    if let Some(set_fn) = unsafe { DDOG_SET_RC_NOTIFY_FN } {
        set_fn(Some(rc_notify_callback));
    } else {
        log::warn!("ddog_set_rc_notify_fn not available, RC notifications will not work");
    }
}

pub fn unregister_for_rc_notifications() {
    log::info!("Unregistering for RC update callbacks");

    if let Some(set_fn) = unsafe { DDOG_SET_RC_NOTIFY_FN } {
        set_fn(None);
    }

    SERVICE_MANAGER.store(std::ptr::null_mut(), Ordering::Release);
}

struct RemoteConfigPath {
    buf: *mut c_char,
    path_free_fn: DdogRemoteConfigPathFreeFn,
}
impl RemoteConfigPath {
    fn new(invariants: *const c_void, target: *const c_void) -> anyhow::Result<Self> {
        let path_fn = match unsafe { DDOG_REMOTE_CONFIG_PATH } {
            Some(f) => f,
            None => {
                return Err(anyhow::anyhow!("ddog_remote_config_path not resolved"));
            }
        };

        let path_free_fn = match unsafe { DDOG_REMOTE_CONFIG_PATH_FREE } {
            Some(f) => f,
            None => {
                return Err(anyhow::anyhow!("ddog_remote_config_path_free not resolved"));
            }
        };

        let buf = path_fn(invariants, target);
        if buf.is_null() {
            return Err(anyhow::anyhow!("ddog_remote_config_path returned null"));
        }

        Ok(Self { buf, path_free_fn })
    }
}
impl Drop for RemoteConfigPath {
    fn drop(&mut self) {
        (self.path_free_fn)(self.buf);
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
